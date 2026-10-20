<?php

namespace Database\Seeders;

use App\Models\Disorder;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SeverityBand;
use App\Models\Subscale;
use Illuminate\Database\Seeder;

class Gad7Seeder extends Seeder
{
    public function run(): void
    {
        $disorder = Disorder::create([
            'name' => 'Generalized Anxiety Disorder (GAD)',
            'name_ar' => 'اضطراب القلق العام',
        ]);

        $exam = Exam::create([
            'disorder_id' => $disorder->id,
            'name' => 'GAD-7 Assessment',
            'name_ar' => 'مقياس القلق العام GAD-7',
            'is_active' => true,
        ]);

        $subscale = Subscale::create([
            'exam_id' => $exam->id,
            'name' => 'General Anxiety',
            'name_ar' => 'القلق العام',
        ]);

        $questionsText = [
            'Feeling nervous, anxious or on edge' => 'الشعور بالعصبية والقلق أو التوتر',
            'Not being able to stop or control worrying' => 'عدم القدرة على إيقاف أو السيطرة على القلق',
            'Worrying too much about different things' => 'القلق أكثر من اللازم حيال أمور مختلفة',
            'Trouble relaxing' => 'صعوبة في الاسترخاء',
            'Being so restless that it is hard to sit still' => 'الشعور بالتململ لدرجة يصعب معها الجلوس بهدوء',
            'Becoming easily annoyed or irritable' => 'سرعة الانزعاج أو الغضب',
            'Feeling afraid as if something awful might happen' => 'الشعور بالخوف كما لو أن شيئاً فظيعاً سيحدث',
        ];

        $optionsText = [
            0 => ['Not at all', 'مطلقاً'],
            1 => ['Several days', 'عدة أيام'],
            2 => ['More than half the days', 'أكثر من نصف الأيام'],
            3 => ['Nearly every day', 'كل يوم تقريباً'],
        ];

        $order = 1;
        foreach ($questionsText as $en => $ar) {
            $question = Question::create([
                'exam_id' => $exam->id,
                'subscale_id' => $subscale->id,
                'text' => $en,
                'text_ar' => $ar,
                'type' => 'single_select',
                'order' => $order++,
            ]);

            foreach ($optionsText as $score => $textPair) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'text' => $textPair[0],
                    'text_ar' => $textPair[1],
                    'score' => $score,
                    'order' => $score + 1,
                ]);
            }
        }

        // Exam Total Bands
        SeverityBand::create(['exam_id' => $exam->id, 'min_score' => 0, 'max_score' => 4, 'label' => 'Minimal Anxiety', 'label_ar' => 'قلق خفيف جداً', 'order' => 1]);
        SeverityBand::create(['exam_id' => $exam->id, 'min_score' => 5, 'max_score' => 9, 'label' => 'Mild Anxiety', 'label_ar' => 'قلق خفيف', 'order' => 2]);
        SeverityBand::create(['exam_id' => $exam->id, 'min_score' => 10, 'max_score' => 14, 'label' => 'Moderate Anxiety', 'label_ar' => 'قلق متوسط', 'order' => 3]);
        SeverityBand::create(['exam_id' => $exam->id, 'min_score' => 15, 'max_score' => 21, 'label' => 'Severe Anxiety', 'label_ar' => 'قلق شديد', 'order' => 4]);
    }
}
