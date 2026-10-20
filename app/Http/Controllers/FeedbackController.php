<?php
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
    
    public function indexPatient(Request $request, Assignment $assignment) {
        if ($request->user()->id !== $assignment->patient_id) {
            abort(403);
        }
        $feedback = $assignment->feedback()->where("is_visible_to_patient", true)->get();
        return response()->json(["data" => $feedback]);
    }
}
