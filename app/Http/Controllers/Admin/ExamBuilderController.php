<?php
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
                "is_active" => $data["is_active"] ?? true,
                "min_level_score" => $data["min_level_score"] ?? null,
                "max_level_score" => $data["max_level_score"] ?? null,
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
