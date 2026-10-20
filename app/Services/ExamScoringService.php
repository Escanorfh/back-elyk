<?php

namespace App\Services;

use App\Models\AttemptResult;
use App\Models\AttemptSubscaleResult;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SeverityBand;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

class ExamScoringService
{
    /**
     * Submit or update an answer for an attempt.
     */
    public function submitAnswer(ExamAttempt $attempt, Question $question, array $selectedOptionIds): ExamAnswer
    {
        // 1. Validate that the question belongs to the same exam as the attempt
        if ($question->exam_id !== $attempt->exam_id) {
            throw new InvalidArgumentException("Question does not belong to the attempt's exam.");
        }

        // 2. Validate selection size based on question type
        $selectedCount = count($selectedOptionIds);
        if ($question->type === 'single_select' && $selectedCount !== 1) {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'Single select questions require exactly one option.'
            ]);
        }
        if ($question->type === 'multi_select' && $selectedCount < 1) {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'Multi-select questions require at least one option.'
            ]);
        }

        // 3. Validate options belong to the question and calculate score
        $options = QuestionOption::whereIn('id', $selectedOptionIds)
            ->where('question_id', $question->id)
            ->get();

        if ($options->count() !== $selectedCount) {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'One or more selected options are invalid for this question.'
            ]);
        }

        $score = $options->sum('score');

        // 4. Upsert the exam_answers row
        $answer = ExamAnswer::updateOrCreate(
            [
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
            ],
            [
                'selected_option_ids' => $selectedOptionIds,
                'score' => $score,
            ]
        );

        return $answer;
    }

    /**
     * Finalize the attempt and compute results.
     * Idempotent: re-submitting an already-completed attempt returns
     * the existing result instead of failing (double-tap / retry safe).
     */
    public function finalizeAttempt(ExamAttempt $attempt): AttemptResult
    {
        if ($attempt->status === 'completed') {
            $existing = $attempt->result()
                ->with(['disorder', 'severityBand', 'attempt.subscaleResults.severityBand', 'attempt.subscaleResults.subscale', 'attempt.patient'])
                ->first();
            if ($existing) {
                return $existing;
            }
            // لا توجد نتيجة مخزنة رغم الاكتمال — نكمل الحساب من جديد.
        }

        $exam = $attempt->exam()->with(['questions', 'severityBands'])->firstOrFail();
        $answers = $attempt->answers()->get()->keyBy('question_id');

        // 1. Validate all questions are answered
        $examQuestionIds = $exam->questions->pluck('id')->toArray();
        $answeredQuestionIds = $answers->keys()->toArray();
        $missingQuestionIds = array_diff($examQuestionIds, $answeredQuestionIds);

        if (!empty($missingQuestionIds)) {
            throw ValidationException::withMessages([
                'exam_attempt' => 'Cannot finalize attempt. Missing answers for questions: ' . implode(', ', $missingQuestionIds)
            ]);
        }

        return DB::transaction(function () use ($attempt, $exam, $answers) {
            // 2. Group answers by subscale and sum scores
            $subscaleTotals = [];
            foreach ($exam->questions as $question) {
                $subscaleId = $question->subscale_id;
                $answer = $answers->get($question->id);

                if (!isset($subscaleTotals[$subscaleId])) {
                    $subscaleTotals[$subscaleId] = 0;
                }
                $subscaleTotals[$subscaleId] += $answer->score;
            }

            $totalExamScore = 0;
            $subscaleBands = SeverityBand::whereIn('subscale_id', array_keys($subscaleTotals))->get()->groupBy('subscale_id');

            // 3. Write attempt_subscale_results and resolve severity bands
            foreach ($subscaleTotals as $subscaleId => $totalScore) {
                $totalExamScore += $totalScore;

                $bandId = null;
                if ($subscaleBands->has($subscaleId)) {
                    $band = $subscaleBands->get($subscaleId)->first(function ($band) use ($totalScore) {
                        return $totalScore >= $band->min_score && $totalScore <= $band->max_score;
                    });
                    $bandId = $band ? $band->id : null;
                }

                AttemptSubscaleResult::updateOrCreate(
                    [
                        'attempt_id' => $attempt->id,
                        'subscale_id' => $subscaleId,
                    ],
                    [
                        'total_score' => $totalScore,
                        'severity_band_id' => $bandId,
                    ]
                );
            }

            // 4. Resolve exam-level severity band
            $examBandId = null;
            $examBand = $exam->severityBands->first(function ($band) use ($totalExamScore) {
                return $totalExamScore >= $band->min_score && $totalExamScore <= $band->max_score;
            });
            $examBandId = $examBand ? $examBand->id : null;

            // 4b. هل المريض عدّى الـ min level؟ لو أيوه => عنده المرض ده.
            // لو الفورم ملهوش min متحدد => السلوك القديم (المرض محسوب).
            $minLevel = $exam->min_level_score;
            $detected = $minLevel === null || $totalExamScore >= (int) $minLevel;

            // 5. Write attempt_results
            $result = AttemptResult::updateOrCreate(
                [
                    'attempt_id' => $attempt->id,
                ],
                [
                    'disorder_id' => $exam->disorder_id,
                    'disorder_detected' => $detected,
                    'total_score' => $totalExamScore,
                    'severity_band_id' => $examBandId,
                    'computed_at' => now(),
                ]
            );

            // 6. Mark attempt as completed
            $attempt->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $result->load(['disorder', 'severityBand', 'attempt.subscaleResults.severityBand', 'attempt.patient']);
            \App\Events\AttemptFinalized::dispatch($result);

            return $result;
        });
    }
}
