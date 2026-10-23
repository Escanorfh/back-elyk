<?php
namespace App\Http\Controllers\Patient;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamRetakeGrant;
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
        $battery = \App\Models\IntakeBattery::where('patient_id', $request->user()->id)
            ->where('status', 'in_progress')->first();
        
        if ($battery) {
            $currentItem = $battery->currentItem;
            if (!$currentItem || $currentItem->exam_id !== $exam->id) {
                return response()->json(["message" => "Please complete your intake exams in the assigned order."], 403);
            }
            $battery->update(['last_activity_at' => now()]);
        }

        // الاختبار لمرة واحدة — لا تُنشأ محاولة جديدة بعد نتيجة مكتملة
        // إلا بموافقة المختص (exam_retake_grants).
        $completed = ExamAttempt::where("patient_id", $request->user()->id)
            ->where("exam_id", $exam->id)
            ->where("status", "completed")
            ->exists();

        if ($completed) {
            $grant = ExamRetakeGrant::where("patient_id", $request->user()->id)
                ->where("exam_id", $exam->id)
                ->latest("granted_at")
                ->first();

            if (!$grant) {
                return response()->json([
                    "message" => "تم إكمال هذا الاختبار من قبل. إعادته تتطلب موافقة المختص."
                ], 403);
            }

            // التصريح لمرة واحدة — يُستهلك مع بدء المحاولة الجديدة.
            $grant->delete();
        }

        $attempt = ExamAttempt::create(["patient_id" => $request->user()->id, "exam_id" => $exam->id]);
        return response()->json(["data" => ["attempt_id" => $attempt->id], "message" => "Attempt started"]);
    }
    public function updateAnswer(ExamAttempt $attempt, Question $question, SubmitAnswerRequest $request) {
        if ($attempt->patient_id !== $request->user()->id) abort(403);
        
        $battery = \App\Models\IntakeBattery::where('patient_id', $request->user()->id)->where('status', 'in_progress')->first();
        if ($battery) $battery->update(['last_activity_at' => now()]);

        $answer = $this->scoringService->submitAnswer($attempt, $question, $request->validated()["selected_option_ids"]);
        return response()->json(["data" => $answer, "message" => "Answer saved"]);
    }
    public function submit(ExamAttempt $attempt, Request $request) {
        if ($attempt->patient_id !== $request->user()->id) abort(403);
        $result = $this->scoringService->finalizeAttempt($attempt);
        $result->loadMissing(['disorder', 'severityBand', 'attempt.subscaleResults.severityBand', 'attempt.subscaleResults.subscale']);
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
