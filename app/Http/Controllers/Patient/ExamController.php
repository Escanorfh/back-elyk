<?php
namespace App\Http\Controllers\Patient;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamRetakeGrant;
use App\Http\Resources\ExamListResource;
use Illuminate\Http\Request;

class ExamController extends Controller {
    public function index(Request $request) {
        $exams = Exam::where("is_active", true)->with("disorder")->get();

        // آخر محاولة مكتملة لكل مقياس — عشان الواجهة تعرض "مكتمل" وتفتح النتيجة.
        $completedAttempts = ExamAttempt::where("patient_id", $request->user()->id)
            ->where("status", "completed")
            ->latest("completed_at")
            ->get()
            ->unique("exam_id")
            ->keyBy("exam_id");

        // تصاريح الإعادة الممنوحة من المختص — تفتح البطاقة لمحاولة جديدة بدل النتيجة.
        $retakeExamIds = ExamRetakeGrant::where("patient_id", $request->user()->id)
            ->pluck("exam_id")
            ->all();

        return ExamListResource::collection($exams->map(function (Exam $exam) use ($completedAttempts, $retakeExamIds) {
            $exam->setAttribute("completed_attempt_id", $completedAttempts->get($exam->id)?->id);
            $exam->setAttribute("retake_available", in_array($exam->id, $retakeExamIds, true));
            return $exam;
        }));
    }
    public function take(Exam $exam) {
        if (!$exam->is_active) abort(404, "Exam not active");
        // Do not return scores/bands
        $exam->load(["questions" => function($q) {
            $q->select("id", "exam_id", "text", "text_ar", "type", "order", "subscale_id");
        }, "questions.subscale" => function($q) {
            $q->select("id", "name", "name_ar");
        }, "questions.options" => function($q) {
            $q->select("id", "question_id", "text", "text_ar", "order");
        }]);
        return response()->json(["data" => $exam]);
    }
}
