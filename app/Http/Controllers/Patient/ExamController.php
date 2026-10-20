<?php
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
            $q->select("id", "exam_id", "text", "text_ar", "type", "order", "subscale_id");
        }, "questions.subscale" => function($q) {
            $q->select("id", "name", "name_ar");
        }, "questions.options" => function($q) {
            $q->select("id", "question_id", "text", "text_ar", "order");
        }]);
        return response()->json(["data" => $exam]);
    }
}
