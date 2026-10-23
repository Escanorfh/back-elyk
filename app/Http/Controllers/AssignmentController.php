<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\PathStep;
use App\Models\User;
use App\Notifications\AssignmentSubmittedNotification;
use Illuminate\Support\Facades\Notification;

class AssignmentController extends Controller {
    public function submit(Request $request, PathStep $step) {
        if ($request->user()->cannot("view", $step->path)) abort(403);
        
        if ($step->status === "submitted") {
            abort(400, "An assignment has already been submitted for this step. Please wait for the admin to review it.");
        }
        if ($step->status === "completed") {
            abort(400, "This step has already been completed and reviewed by the admin. You cannot submit again.");
        }
        if ($step->status !== "active") {
            abort(400, "This step is not currently active. It may be pending release by the admin.");
        }

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
