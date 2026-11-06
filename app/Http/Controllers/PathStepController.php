<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\PathStep;
use App\Models\PatientPath;
use App\Services\Calendar\PathStepScheduleService;

class PathStepController extends Controller {
    public function __construct(protected PathStepScheduleService $schedule) {}

    public function store(Request $request, $patientId, $disorderId) {
        $validated = $request->validate([
            "title" => "nullable|string|max:500",
            "audio_media_id" => "nullable|exists:media_items,id",
            "pdf_media_id" => "nullable|exists:media_items,id",
            "order" => "required|integer"
        ]);
        if (empty($validated["title"]) && empty($validated["audio_media_id"]) && empty($validated["pdf_media_id"])) {
            abort(400, "At least one of title / audio / pdf must be provided.");
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
            "title" => "nullable|string|max:500",
            "audio_media_id" => "nullable|exists:media_items,id",
            "pdf_media_id" => "nullable|exists:media_items,id",
            "order" => "sometimes|integer"
        ]);
        $step->update($validated);
        return response()->json(["data" => $step]);
    }

    // جدولة الخطوة داخل التقويم: due_at وحده ما يُدخلها النافذة، وحالتها العلاجية لا تُمسّ هنا.
    public function scheduleDate(Request $request, PathStep $step) {
        $validated = $request->validate(PathStepScheduleService::rules());
        $step = $this->schedule->schedule($step, $validated, $request->user());
        return response()->json(["data" => $step]);
    }

    // سحب الموعد: الخطوة تعود «بلا تاريخ» وتتوقف تذكيراتها المعلّقة.
    public function clearSchedule(PathStep $step) {
        return response()->json(["data" => $this->schedule->clearDate($step)]);
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
