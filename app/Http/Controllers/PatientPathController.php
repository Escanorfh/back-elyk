<?php
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
            $step->assignment->setRelation("feedback", $step->assignment->feedback->where("is_visible_to_patient", true)->values());
        }
        return response()->json(["data" => $step]);
    }

    public function myPathDetail(Request $request, PatientPath $path) {
        if ($request->user()->cannot("view", $path)) abort(403);
        $path->load("steps.audioMedia", "steps.pdfMedia", "steps.assignment.feedback", "disorder");
        // Hide invisible feedback from patients.
        foreach ($path->steps as $step) {
            if ($step->assignment) {
                $step->assignment->setRelation("feedback", $step->assignment->feedback->where("is_visible_to_patient", true)->values());
            }
        }
        return response()->json(["data" => $path]);
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

    public function markVoiceCompleted(Request $request, PatientPath $path) {
        if ($request->user()->cannot("view", $path)) abort(403);
        $step = $path->currentStep;
        if (!$step) abort(404);

        // Notify Admin that the patient is finishing the voice note
        $admin = User::where('role', 'admin')->first();
        if ($admin) {
            $admin->notify(new \App\Notifications\StepSubmittedNotification($path->patient, $step));
        }

        return response()->json(["message" => "Voice completion recorded"]);
    }

    public function submitStep(Request $request, PatientPath $path) {        if ($request->user()->cannot("view", $path)) abort(403);
        $step = $path->currentStep;
        if (!$step) abort(404);

        $step->update([
            "status" => "submitted",
            "submitted_at" => now()
        ]);

        // Notify Admin
        $admin = User::where('role', 'admin')->first();
        if ($admin) {
            $admin->notify(new \App\Notifications\StepSubmittedNotification($path->patient, $step));
        }

        return response()->json(["message" => "Step submitted successfully"]);
    }

    // تحديد يوم اللايف بعد انتهاء آخر مرحلة صوتية (أدمن فقط).
    // patient_ids: المرضى المدعوون — يجب أن يشتركوا جميعاً في نفس المرض.
    public function scheduleLive(Request $request, PatientPath $path) {
        if ($request->user()->role !== "admin") abort(403);
        $validated = $request->validate([
            "scheduled_at" => "required|date",
            "title" => "nullable|string|max:255",
            "patient_ids" => "nullable|array",
            "patient_ids.*" => "integer|exists:users,id",
        ]);

        $path->load("steps", "patient");
        if (!$path->patient) abort(404, "Patient not found.");

        // لا يجوز الجدولة طالما توجد خطوات معلقة لم تُراجَع بعد.
        $remaining = $path->steps->whereIn("status", ["pending", "active"])->count();
        if ($remaining > 0) abort(400, "Path still has pending steps.");

        // المدعوون: المحددون أو صاحب المسار — وكلهم بنفس المرض.
        $ids = collect($validated["patient_ids"] ?? [$path->patient_id])
            ->map(fn($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty) $ids = collect([$path->patient_id]);
        foreach ($ids as $pid) {
            $u = \App\Models\User::find($pid);
            if (!$u || $u->role !== "patient") {
                abort(422, "Invited users must be patients.");
            }
            $same = PatientPath::where("patient_id", $pid)
                ->where("disorder_id", $path->disorder_id)->exists();
            if (!$same) {
                abort(422, "All invited patients must share the same disorder.");
            }
        }

        // إتمام الخطوة الحالية (إن وجدت) وإغلاق المسار — اللايف هو المرحلة الأخيرة.
        if ($path->currentStep) {
            $path->currentStep->update(["status" => "completed", "completed_at" => now()]);
        }
        $path->update(["status" => "completed", "current_step_id" => null]);

        $session = \App\Models\LiveSession::create([
            "admin_id" => $request->user()->id,
            "patient_id" => $path->patient_id,
            "title" => $validated["title"] ?? "جلسة لايف متابعة",
            "status" => "scheduled",
            "scheduled_at" => $validated["scheduled_at"],
            "livekit_room_name" => "room_" . \Illuminate\Support\Str::random(10),
        ]);
        foreach ($ids as $pid) {
            \App\Models\LiveSessionPatient::firstOrCreate([
                "live_session_id" => $session->id,
                "patient_id" => $pid,
            ]);
        }
        $session->refresh();

        // إشعار المدعوين (صفحة الإشعارات + دفع للجهاز).
        $invited = \App\Models\User::whereIn("id", $ids)->get();
        foreach ($invited as $patient) {
            $patient->notify(new \App\Notifications\LiveScheduledNotification($session));
        }

        return response()->json(["message" => "Live day scheduled", "data" => $session]);
    }
}
