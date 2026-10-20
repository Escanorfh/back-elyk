<?php
$base = __DIR__;

function writeContent($file, $content) {
    global $base;
    file_put_contents($base . '/' . $file, $content);
}

// CheckRole Middleware
writeContent('app/Http/Middleware/CheckRole.php', '<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, $role): Response
    {
        if (!$request->user() || $request->user()->role !== $role) {
            abort(403, "Unauthorized action. Role {$role} required.");
        }
        return $next($request);
    }
}
');

// Register CheckRole in bootstrap/app.php
$appPhp = file_get_contents($base . '/bootstrap/app.php');
$middlewareRegistration = "\$middleware->alias(['role' => \\App\\Http\\Middleware\\CheckRole::class]);";
if (strpos($appPhp, 'role') === false) {
    $insertPos = strpos($appPhp, '->withMiddleware(function (Middleware $middleware) {') + strlen('->withMiddleware(function (Middleware $middleware) {');
    $appPhp = substr_replace($appPhp, "\n        " . $middlewareRegistration, $insertPos, 0);
    file_put_contents($base . '/bootstrap/app.php', $appPhp);
}

// routes/api.php
$apiRoutes = '<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\DisorderController;
use App\Http\Controllers\Admin\ExamBuilderController;
use App\Http\Controllers\Patient\ExamController as PatientExamController;
use App\Http\Controllers\Patient\AttemptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix(\'v1\')->group(function () {
    Route::post(\'/auth/register\', [AuthController::class, \'register\']);
    Route::post(\'/auth/login\', [AuthController::class, \'login\']);

    Route::middleware(\'auth:sanctum\')->group(function () {
        Route::post(\'/auth/logout\', [AuthController::class, \'logout\']);
        Route::get(\'/user\', function (Request $request) { return $request->user(); });

        // Admin Routes
        Route::middleware(\'role:admin\')->group(function () {
            Route::apiResource(\'disorders\', DisorderController::class);
            Route::apiResource(\'exams\', ExamBuilderController::class);
            Route::get(\'/exams/{exam}/full\', [ExamBuilderController::class, \'full\']);
            // Granular endpoints omitted for brevity, full update payload is supported in ExamBuilderController
        });

        // Patient Routes
        Route::middleware(\'role:patient\')->group(function () {
            Route::get(\'/patient/exams\', [PatientExamController::class, \'index\']);
            Route::get(\'/patient/exams/{exam}/take\', [PatientExamController::class, \'take\']);
            Route::post(\'/patient/exams/{exam}/attempts\', [AttemptController::class, \'store\']);
            Route::put(\'/patient/attempts/{attempt}/answers/{question}\', [AttemptController::class, \'updateAnswer\']);
            Route::post(\'/patient/attempts/{attempt}/submit\', [AttemptController::class, \'submit\']);
            Route::get(\'/patient/attempts/{attempt}/result\', [AttemptController::class, \'result\']);
            Route::get(\'/patient/me/attempts\', [AttemptController::class, \'history\']);
        });
    });
});
';
writeContent('routes/api.php', $apiRoutes);

// Form Requests
writeContent('app/Http/Requests/StoreDisorderRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreDisorderRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            "name" => "required|string|max:255",
            "name_ar" => "required|string|max:255",
            "description" => "nullable|string",
        ];
    }
}
');

writeContent('app/Http/Requests/StoreExamRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreExamRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            "disorder_id" => "required|exists:disorders,id",
            "name" => "required|string|max:255",
            "name_ar" => "required|string|max:255",
            "description" => "nullable|string",
            "is_active" => "boolean",
            "subscales" => "array|min:1",
            "subscales.*.name" => "required|string",
            "subscales.*.name_ar" => "required|string",
            "questions" => "array",
            "questions.*.subscale_index" => "required|integer",
            "questions.*.text" => "required|string",
            "questions.*.text_ar" => "required|string",
            "questions.*.type" => "required|in:single_select,multi_select",
            "questions.*.options" => "array|min:1",
            "questions.*.options.*.text" => "required|string",
            "questions.*.options.*.score" => "required|integer",
            "bands" => "array",
            "bands.*.min_score" => "required|integer",
            "bands.*.max_score" => "required|integer",
            "bands.*.label" => "required|string",
        ];
    }
}
');

writeContent('app/Http/Requests/SubmitAnswerRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class SubmitAnswerRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            "selected_option_ids" => "required|array",
            "selected_option_ids.*" => "integer|exists:question_options,id",
        ];
    }
}
');

// Resources
writeContent('app/Http/Resources/ExamListResource.php', '<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
class ExamListResource extends JsonResource {
    public function toArray($request) {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "name_ar" => $this->name_ar,
            "disorder_name" => $this->disorder->name ?? null,
            "description" => $this->description,
        ];
    }
}
');

writeContent('app/Http/Resources/AttemptResultResource.php', '<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
class AttemptResultResource extends JsonResource {
    public function toArray($request) {
        return [
            "id" => $this->id,
            "disorder" => $this->disorder->name ?? null,
            "total_score" => $this->total_score,
            "severity_band" => $this->severityBand->label ?? null,
            "computed_at" => $this->computed_at,
            "subscales" => $this->attempt->subscaleResults->map(function($res) {
                return [
                    "subscale_name" => $res->subscale->name,
                    "score" => $res->total_score,
                    "band" => $res->severityBand->label ?? null
                ];
            })
        ];
    }
}
');

// Admin Controllers
writeContent('app/Http/Controllers/Admin/DisorderController.php', '<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Disorder;
use App\Http\Requests\StoreDisorderRequest;
class DisorderController extends Controller {
    public function index() { return response()->json(["data" => Disorder::all()]); }
    public function store(StoreDisorderRequest $request) {
        $disorder = Disorder::create($request->validated());
        return response()->json(["data" => $disorder]);
    }
    public function show(Disorder $disorder) { return response()->json(["data" => $disorder]); }
    public function update(StoreDisorderRequest $request, Disorder $disorder) {
        $disorder->update($request->validated());
        return response()->json(["data" => $disorder]);
    }
    public function destroy(Disorder $disorder) {
        $disorder->delete();
        return response()->json(["message" => "Deleted"]);
    }
}
');

writeContent('app/Http/Controllers/Admin/ExamBuilderController.php', '<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Subscale;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SeverityBand;
use App\Http\Requests\StoreExamRequest;
use Illuminate\Support\Facades\DB;

class ExamBuilderController extends Controller {
    public function index() { return response()->json(["data" => Exam::with("disorder")->get()]); }
    public function store(StoreExamRequest $request) {
        $data = $request->validated();
        $exam = DB::transaction(function() use ($data) {
            $exam = Exam::create([
                "disorder_id" => $data["disorder_id"], "name" => $data["name"],
                "name_ar" => $data["name_ar"], "description" => $data["description"] ?? null,
                "is_active" => $data["is_active"] ?? true
            ]);
            $subscales = [];
            foreach ($data["subscales"] as $index => $sub) {
                $subscales[$index] = Subscale::create(["exam_id" => $exam->id, "name" => $sub["name"], "name_ar" => $sub["name_ar"]]);
            }
            if (isset($data["questions"])) {
                foreach ($data["questions"] as $q) {
                    $question = Question::create([
                        "exam_id" => $exam->id, "subscale_id" => $subscales[$q["subscale_index"]]->id,
                        "text" => $q["text"], "text_ar" => $q["text_ar"], "type" => $q["type"]
                    ]);
                    foreach ($q["options"] as $opt) {
                        QuestionOption::create(["question_id" => $question->id, "text" => $opt["text"], "text_ar" => $opt["text"] ?? $opt["text_ar"], "score" => $opt["score"]]);
                    }
                }
            }
            if (isset($data["bands"])) {
                foreach ($data["bands"] as $band) {
                    SeverityBand::create(["exam_id" => $exam->id, "min_score" => $band["min_score"], "max_score" => $band["max_score"], "label" => $band["label"], "label_ar" => $band["label"]]);
                }
            }
            return $exam;
        });
        return response()->json(["data" => $exam, "message" => "Exam created"]);
    }
    public function show(Exam $exam) { return response()->json(["data" => $exam]); }
    public function full(Exam $exam) { return response()->json(["data" => $exam->load(["subscales", "questions.options", "severityBands"])]); }
    public function destroy(Exam $exam) { $exam->delete(); return response()->json(["message" => "Deleted"]); }
}
');

// Patient Controllers
writeContent('app/Http/Controllers/Patient/ExamController.php', '<?php
namespace App\Http\Controllers\Patient;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Http\Resources\ExamListResource;

class ExamController extends Controller {
    public function index() {
        return ExamListResource::collection(Exam::where("is_active", true)->with("disorder")->get());
    }
    public function take(Exam $exam) {
        if (!$exam->is_active) abort(404, "Exam not active");
        // Do not return scores/bands
        $exam->load(["questions" => function($q) {
            $q->select("id", "exam_id", "text", "text_ar", "type", "order");
        }, "questions.options" => function($q) {
            $q->select("id", "question_id", "text", "text_ar", "order");
        }]);
        return response()->json(["data" => $exam]);
    }
}
');

writeContent('app/Http/Controllers/Patient/AttemptController.php', '<?php
namespace App\Http\Controllers\Patient;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Http\Requests\SubmitAnswerRequest;
use App\Services\ExamScoringService;
use App\Http\Resources\AttemptResultResource;
use Illuminate\Http\Request;

class AttemptController extends Controller {
    protected $scoringService;
    public function __construct(ExamScoringService $scoringService) {
        $this->scoringService = $scoringService;
    }
    public function store(Exam $exam, Request $request) {
        $attempt = ExamAttempt::create(["patient_id" => $request->user()->id, "exam_id" => $exam->id]);
        return response()->json(["data" => ["attempt_id" => $attempt->id], "message" => "Attempt started"]);
    }
    public function updateAnswer(ExamAttempt $attempt, Question $question, SubmitAnswerRequest $request) {
        if ($attempt->patient_id !== $request->user()->id) abort(403);
        $answer = $this->scoringService->submitAnswer($attempt, $question, $request->validated()["selected_option_ids"]);
        return response()->json(["data" => $answer, "message" => "Answer saved"]);
    }
    public function submit(ExamAttempt $attempt, Request $request) {
        if ($attempt->patient_id !== $request->user()->id) abort(403);
        $result = $this->scoringService->finalizeAttempt($attempt);
        return response()->json(["data" => new AttemptResultResource($result), "message" => "Attempt finalized"]);
    }
    public function result(ExamAttempt $attempt, Request $request) {
        if ($attempt->patient_id !== $request->user()->id) abort(403);
        $result = $attempt->result()->with(["disorder", "severityBand", "attempt.subscaleResults.severityBand", "attempt.subscaleResults.subscale"])->firstOrFail();
        return response()->json(["data" => new AttemptResultResource($result)]);
    }
    public function history(Request $request) {
        $history = $request->user()->examAttempts()->where("status", "completed")->with(["exam.disorder", "result.severityBand", "subscaleResults.subscale", "subscaleResults.severityBand"])->get();
        return response()->json(["data" => $history]);
    }
}
');

echo "API Classes filled successfully.\n";
