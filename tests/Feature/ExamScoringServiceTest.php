<?php

namespace Tests\Feature;

use App\Models\Disorder;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SeverityBand;
use App\Models\Subscale;
use App\Models\User;
use App\Services\ExamScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExamScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ExamScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExamScoringService();
    }

    private function createExamSetup()
    {
        $user = User::create([
            'name' => 'Patient 1',
            'email' => 'patient@test.com',
            'password' => bcrypt('password'),
            'role' => 'patient',
        ]);

        $disorder = Disorder::create(['name' => 'Test Disorder', 'name_ar' => 'Test Disorder AR']);
        $exam = Exam::create(['disorder_id' => $disorder->id, 'name' => 'Test Exam', 'name_ar' => 'Test Exam AR']);
        
        $subscale1 = Subscale::create(['exam_id' => $exam->id, 'name' => 'Subscale 1', 'name_ar' => 'S1 AR']);
        $subscale2 = Subscale::create(['exam_id' => $exam->id, 'name' => 'Subscale 2', 'name_ar' => 'S2 AR']);

        // Single select question
        $q1 = Question::create(['exam_id' => $exam->id, 'subscale_id' => $subscale1->id, 'text' => 'Q1', 'text_ar' => 'Q1', 'type' => 'single_select']);
        $o1 = QuestionOption::create(['question_id' => $q1->id, 'text' => 'O1', 'text_ar' => 'O1', 'score' => 2]);
        $o2 = QuestionOption::create(['question_id' => $q1->id, 'text' => 'O2', 'text_ar' => 'O2', 'score' => 5]);

        // Multi select question
        $q2 = Question::create(['exam_id' => $exam->id, 'subscale_id' => $subscale2->id, 'text' => 'Q2', 'text_ar' => 'Q2', 'type' => 'multi_select']);
        $o3 = QuestionOption::create(['question_id' => $q2->id, 'text' => 'O3', 'text_ar' => 'O3', 'score' => 3]);
        $o4 = QuestionOption::create(['question_id' => $q2->id, 'text' => 'O4', 'text_ar' => 'O4', 'score' => 4]);

        // Severity Bands
        // Subscale 1: 0-3 Low, 4-10 High
        SeverityBand::create(['subscale_id' => $subscale1->id, 'min_score' => 0, 'max_score' => 3, 'label' => 'Low', 'label_ar' => 'Low']);
        SeverityBand::create(['subscale_id' => $subscale1->id, 'min_score' => 4, 'max_score' => 10, 'label' => 'High', 'label_ar' => 'High']);

        // Exam total: 0-6 Mild, 7-20 Severe
        SeverityBand::create(['exam_id' => $exam->id, 'min_score' => 0, 'max_score' => 6, 'label' => 'Mild', 'label_ar' => 'Mild']);
        SeverityBand::create(['exam_id' => $exam->id, 'min_score' => 7, 'max_score' => 20, 'label' => 'Severe', 'label_ar' => 'Severe']);

        $attempt = ExamAttempt::create(['patient_id' => $user->id, 'exam_id' => $exam->id]);

        return [$exam, $attempt, $q1, $q2, $o1, $o2, $o3, $o4];
    }

    public function test_submit_answer_single_select()
    {
        list($exam, $attempt, $q1, $q2, $o1, $o2, $o3, $o4) = $this->createExamSetup();

        $answer = $this->service->submitAnswer($attempt, $q1, [$o1->id]);
        
        $this->assertEquals(2, $answer->score);
        $this->assertEquals([$o1->id], $answer->selected_option_ids);
    }

    public function test_submit_answer_multi_select()
    {
        list($exam, $attempt, $q1, $q2, $o1, $o2, $o3, $o4) = $this->createExamSetup();

        $answer = $this->service->submitAnswer($attempt, $q2, [$o3->id, $o4->id]);
        
        $this->assertEquals(7, $answer->score);
        $this->assertEquals([$o3->id, $o4->id], $answer->selected_option_ids);
    }

    public function test_single_select_fails_with_multiple_options()
    {
        list($exam, $attempt, $q1, $q2, $o1, $o2, $o3, $o4) = $this->createExamSetup();

        $this->expectException(ValidationException::class);
        $this->service->submitAnswer($attempt, $q1, [$o1->id, $o2->id]);
    }

    public function test_finalize_attempt_computes_correct_bands_and_scores()
    {
        list($exam, $attempt, $q1, $q2, $o1, $o2, $o3, $o4) = $this->createExamSetup();

        // Submit q1 (score 5, Subscale 1 -> High)
        $this->service->submitAnswer($attempt, $q1, [$o2->id]);
        // Submit q2 (score 7, Subscale 2 -> no bands)
        $this->service->submitAnswer($attempt, $q2, [$o3->id, $o4->id]);

        // Finalize
        $result = $this->service->finalizeAttempt($attempt);

        // Total score = 5 + 7 = 12
        $this->assertEquals(12, $result->total_score);
        $this->assertEquals('Severe', $result->severityBand->label); // 12 is in 7-20

        // Subscale 1 result
        $s1Result = $attempt->subscaleResults()->where('subscale_id', $q1->subscale_id)->first();
        $this->assertEquals(5, $s1Result->total_score);
        $this->assertEquals('High', $s1Result->severityBand->label); // 5 is in 4-10
        
        // Subscale 2 result
        $s2Result = $attempt->subscaleResults()->where('subscale_id', $q2->subscale_id)->first();
        $this->assertEquals(7, $s2Result->total_score);
        $this->assertNull($s2Result->severityBand); // No bands defined for subscale 2

        // Attempt is marked completed
        $this->assertEquals('completed', $attempt->fresh()->status);
    }

    public function test_finalize_fails_when_missing_answers()
    {
        list($exam, $attempt, $q1, $q2, $o1, $o2, $o3, $o4) = $this->createExamSetup();

        // Only answer Q1
        $this->service->submitAnswer($attempt, $q1, [$o1->id]);

        $this->expectException(ValidationException::class);
        $this->service->finalizeAttempt($attempt);
    }
}

