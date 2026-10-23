<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Disorder;
use App\Models\Exam;
use App\Models\Subscale;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SeverityBand;
use App\Models\ExamAttempt;
use App\Services\ExamScoringService;
use Illuminate\Support\Facades\Event;
use App\Events\AttemptFinalized;

class NotificationTriggerTest extends TestCase {
    use RefreshDatabase;

    public function test_severe_result_triggers_event() {
        Event::fake([AttemptFinalized::class]);

        $disorder = Disorder::create(["name" => "Test", "name_ar" => "اختبار"]);
        $exam = Exam::create(["disorder_id" => $disorder->id, "name" => "E1", "name_ar" => "E1", "description" => "D"]);
        $subscale = Subscale::create(["exam_id" => $exam->id, "name" => "S1", "name_ar" => "S1"]);
        
        // This band is alert_worthy
        SeverityBand::create(["exam_id" => $exam->id, "min_score" => 10, "max_score" => 20, "label" => "Severe", "label_ar" => "شديد", "is_alert_worthy" => true]);
        
        $question = Question::create(["exam_id" => $exam->id, "subscale_id" => $subscale->id, "text" => "Q1", "text_ar" => "Q1", "type" => "single_select", "order" => 1]);
        $option = QuestionOption::create(["question_id" => $question->id, "text" => "O1", "text_ar" => "O1", "score" => 15, "order" => 1]);

        $patient = User::factory()->create(["role" => "patient"]);
        $attempt = ExamAttempt::create(["patient_id" => $patient->id, "exam_id" => $exam->id]);

        $service = new ExamScoringService();
        $service->submitAnswer($attempt, $question, [$option->id]);
        
        $service->finalizeAttempt($attempt);

        Event::assertDispatched(AttemptFinalized::class);
    }
}
