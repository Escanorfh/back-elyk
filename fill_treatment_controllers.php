<?php
$base = __DIR__;

function writeClass($file, $content) {
    global $base;
    $dir = dirname($base . '/' . $file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeClass('app/Http/Controllers/MediaItemController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\MediaItem;
use App\Models\Disorder;

class MediaItemController extends Controller {
    public function index(Request $request, Disorder $disorder) {
        $items = MediaItem::where("disorder_id", $disorder->id)
            ->where("is_active", true)
            ->get();
        return response()->json(["data" => $items]);
    }
    
    public function store(Request $request, Disorder $disorder) {
        $validated = $request->validate([
            "type" => "required|in:audio,pdf",
            "title" => "required|string",
            "title_ar" => "nullable|string",
            "description" => "nullable|string",
            "file" => "required|file|mimes:mp3,m4a,wav,pdf|max:20480",
            "duration_seconds" => "nullable|integer"
        ]);
        
        $path = $request->file("file")->store("media", "public");

        $item = MediaItem::create([
            "disorder_id" => $disorder->id,
            "type" => $validated["type"],
            "title" => $validated["title"],
            "title_ar" => $validated["title_ar"] ?? null,
            "description" => $validated["description"] ?? null,
            "file_path" => $path,
            "duration_seconds" => $validated["duration_seconds"] ?? null,
            "uploaded_by" => $request->user()->id,
            "is_active" => true
        ]);
        
        return response()->json(["data" => $item], 201);
    }

    public function update(Request $request, MediaItem $item) {
        $validated = $request->validate([
            "title" => "sometimes|string",
            "title_ar" => "nullable|string",
            "description" => "nullable|string",
            "is_active" => "sometimes|boolean"
        ]);
        
        $item->update($validated);
        return response()->json(["data" => $item]);
    }
    
    public function destroy(MediaItem $item) {
        $inUse = \App\Models\PathStep::where("audio_media_id", $item->id)->orWhere("pdf_media_id", $item->id)->exists();
        if ($inUse) {
            abort(400, "Media item is in use by path steps. Please deactivate it instead.");
        }
        $item->delete();
        return response()->json(["message" => "Deleted"]);
    }
}
');

writeClass('app/Http/Controllers/PatientPathController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\PatientPath;
use App\Models\User;
use App\Notifications\StepReleasedNotification;

class PatientPathController extends Controller {
    public function indexAdmin(Request $request, User $patient) {
        $paths = PatientPath::where("patient_id", $patient->id)->with("disorder")->get();
        return response()->json(["data" => $paths]);
    }

    public function showAdmin(Request $request, PatientPath $path) {
        $path->load("steps.audioMedia", "steps.pdfMedia", "steps.assignment.feedback", "disorder", "patient");
        return response()->json(["data" => $path]);
    }

    public function myPaths(Request $request) {
        $paths = PatientPath::where("patient_id", $request->user()->id)->with("disorder")->get();
        return response()->json(["data" => $paths]);
    }
    
    public function myCurrentStep(Request $request, PatientPath $path) {
        if ($request->user()->cannot("view", $path)) abort(403);
        $step = $path->currentStep()->with("audioMedia", "pdfMedia", "assignment.feedback")->first();
        // Hide invisible feedback
        if ($step && $step->assignment) {
            $step->assignment->setRelation("feedback", $step->assignment->feedback->where("is_visible_to_patient", true));
        }
        return response()->json(["data" => $step]);
    }

    public function reorder(Request $request, PatientPath $path) {
        $validated = $request->validate(["step_ids" => "required|array"]);
        foreach ($validated["step_ids"] as $index => $id) {
            $path->steps()->where("id", $id)->update(["order" => $index + 1]);
        }
        return response()->json(["message" => "Reordered"]);
    }

    public function releaseNext(Request $request, PatientPath $path) {
        $current = $path->currentStep;
        
        if ($current && $current->status !== "submitted" && $current->status !== "completed") {
            abort(400, "Current step has not been submitted yet.");
        }

        if ($current) {
            $current->update(["status" => "completed", "completed_at" => now()]);
        }
        
        $next = $path->steps()->where("status", "pending")->orderBy("order", "asc")->first();
        if ($next) {
            $next->update(["status" => "active", "unlocked_at" => now()]);
            $path->update(["status" => "active", "current_step_id" => $next->id]);
            $path->patient->notify(new StepReleasedNotification($next));
            return response()->json(["message" => "Next step released", "data" => $next]);
        } else {
            $path->update(["status" => "completed", "current_step_id" => null]);
            return response()->json(["message" => "Path completed"]);
        }
    }
}
');

writeClass('app/Http/Controllers/PathStepController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\PathStep;
use App\Models\PatientPath;

class PathStepController extends Controller {
    public function store(Request $request, $patientId, $disorderId) {
        $validated = $request->validate([
            "audio_media_id" => "nullable|exists:media_items,id",
            "pdf_media_id" => "nullable|exists:media_items,id",
            "order" => "required|integer"
        ]);
        if (empty($validated["audio_media_id"]) && empty($validated["pdf_media_id"])) {
            abort(400, "At least one media item must be provided.");
        }

        $path = PatientPath::firstOrCreate(
            ["patient_id" => $patientId, "disorder_id" => $disorderId],
            ["status" => "draft"]
        );

        $step = $path->steps()->create($validated);
        return response()->json(["data" => $step], 201);
    }

    public function update(Request $request, PathStep $step) {
        if ($step->status !== "pending") abort(400, "Can only edit pending steps.");
        $validated = $request->validate([
            "audio_media_id" => "nullable|exists:media_items,id",
            "pdf_media_id" => "nullable|exists:media_items,id",
            "order" => "sometimes|integer"
        ]);
        $step->update($validated);
        return response()->json(["data" => $step]);
    }

    public function destroy(PathStep $step) {
        if ($step->status !== "pending") abort(400, "Can only delete pending steps.");
        $step->delete();
        return response()->json(["message" => "Deleted"]);
    }
    
    public function reopen(PathStep $step) {
        $step->update(["status" => "active"]);
        if ($step->assignment) $step->assignment->delete();
        return response()->json(["message" => "Reopened"]);
    }
}
');

writeClass('app/Http/Controllers/AssignmentController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\PathStep;
use App\Models\User;
use App\Notifications\AssignmentSubmittedNotification;
use Illuminate\Support\Facades\Notification;

class AssignmentController extends Controller {
    public function submit(Request $request, PathStep $step) {
        if ($request->user()->cannot("view", $step->path)) abort(403);
        if ($step->status !== "active") abort(400, "Step is not active.");

        $validated = $request->validate([
            "text_response" => "nullable|string",
            "file" => "nullable|file|max:10240"
        ]);
        
        if (empty($validated["text_response"]) && !$request->hasFile("file")) {
            abort(400, "Provide text or a file.");
        }

        $filePath = null;
        if ($request->hasFile("file")) {
            $filePath = $request->file("file")->store("assignments", "public");
        }

        $assignment = $step->assignment()->create([
            "patient_id" => $request->user()->id,
            "text_response" => $validated["text_response"] ?? null,
            "file_path" => $filePath
        ]);

        $step->update(["status" => "submitted", "submitted_at" => now()]);

        $admins = User::where("role", "admin")->get();
        Notification::send($admins, new AssignmentSubmittedNotification($assignment));

        return response()->json(["data" => $assignment], 201);
    }
    
    public function showAdmin(PathStep $step) {
        return response()->json(["data" => $step->assignment->load("feedback")]);
    }
}
');

writeClass('app/Http/Controllers/FeedbackController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Assignment;
use App\Models\AssignmentFeedback;
use App\Notifications\FeedbackAvailableNotification;

class FeedbackController extends Controller {
    public function store(Request $request, Assignment $assignment) {
        $validated = $request->validate([
            "note_text" => "nullable|string",
            "file" => "nullable|file|max:10240",
            "is_visible_to_patient" => "boolean"
        ]);

        $filePath = null;
        if ($request->hasFile("file")) {
            $filePath = $request->file("file")->store("feedback", "public");
        }

        $feedback = $assignment->feedback()->create([
            "admin_id" => $request->user()->id,
            "note_text" => $validated["note_text"] ?? null,
            "file_path" => $filePath,
            "is_visible_to_patient" => $validated["is_visible_to_patient"] ?? false
        ]);

        if ($feedback->is_visible_to_patient) {
            $assignment->patient->notify(new FeedbackAvailableNotification($feedback));
        }

        return response()->json(["data" => $feedback], 201);
    }
    
    public function update(Request $request, AssignmentFeedback $feedback) {
        $validated = $request->validate([
            "note_text" => "sometimes|string",
            "is_visible_to_patient" => "sometimes|boolean"
        ]);
        
        $wasVisible = $feedback->is_visible_to_patient;
        $feedback->update($validated);
        
        if (!$wasVisible && $feedback->is_visible_to_patient) {
            $feedback->assignment->patient->notify(new FeedbackAvailableNotification($feedback));
        }

        return response()->json(["data" => $feedback]);
    }
}
');

// Update API Routes
$routes = file_get_contents($base . '/routes/api.php');

$adminRoutes = '
        // Treatment Path - Admin
        Route::apiResource("disorders.media-items", \App\Http\Controllers\MediaItemController::class)->shallow();
        
        Route::get("/patients/{patient}/paths", [\App\Http\Controllers\PatientPathController::class, "indexAdmin"]);
        Route::get("/patient-paths/{path}", [\App\Http\Controllers\PatientPathController::class, "showAdmin"]);
        Route::post("/patients/{patient}/paths/{disorder}/steps", [\App\Http\Controllers\PathStepController::class, "store"]);
        Route::put("/path-steps/{step}", [\App\Http\Controllers\PathStepController::class, "update"]);
        Route::delete("/path-steps/{step}", [\App\Http\Controllers\PathStepController::class, "destroy"]);
        Route::put("/patient-paths/{path}/reorder", [\App\Http\Controllers\PatientPathController::class, "reorder"]);
        Route::post("/patient-paths/{path}/release-next", [\App\Http\Controllers\PatientPathController::class, "releaseNext"]);
        Route::post("/path-steps/{step}/reopen", [\App\Http\Controllers\PathStepController::class, "reopen"]);
        
        Route::get("/path-steps/{step}/assignment", [\App\Http\Controllers\AssignmentController::class, "showAdmin"]);
        Route::post("/assignments/{assignment}/feedback", [\App\Http\Controllers\FeedbackController::class, "store"]);
        Route::put("/assignment-feedback/{feedback}", [\App\Http\Controllers\FeedbackController::class, "update"]);
';

$patientRoutes = '
        // Treatment Path - Patient
        Route::get("/me/paths", [\App\Http\Controllers\PatientPathController::class, "myPaths"]);
        Route::get("/me/paths/{path}/current-step", [\App\Http\Controllers\PatientPathController::class, "myCurrentStep"]);
        Route::post("/path-steps/{step}/assignment", [\App\Http\Controllers\AssignmentController::class, "submit"]);
';

$routes = preg_replace('/(\/\/ Admin Routes)/', $adminRoutes . "\n        $1", $routes);
$routes = preg_replace('/(\/\/ Patient Routes)/', $patientRoutes . "\n        $1", $routes);
file_put_contents($base . '/routes/api.php', $routes);

echo "Controllers populated.\n";
