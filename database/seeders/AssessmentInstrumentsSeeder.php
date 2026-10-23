<?php

namespace Database\Seeders;

use App\Models\Disorder;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SeverityBand;
use App\Models\Subscale;
use Illuminate\Database\Seeder;

class AssessmentInstrumentsSeeder extends Seeder
{
    /**
     * يزرع المقاييس السريرية الأساسية: دافيسون للصدمة، GAD-7، وY-BOCS.
     */
    public function run(): void
    {
        $this->seedDavidsonTraumaScale();
        $this->seedGad7();
        $this->seedYbocs();
    }

    private function seedExam(array $disorderData, array $examData, array $subscales, array $bands): Exam
    {
        $disorder = Disorder::firstOrCreate(
            ['name_ar' => $disorderData['name_ar']],
            $disorderData
        );

        $existing = Exam::where('name_ar', $examData['name_ar'])->first();
        if ($existing) {
            return $existing;
        }

        $exam = Exam::create(array_merge($examData, ['disorder_id' => $disorder->id, 'is_active' => true]));

        $subscaleModels = [];
        foreach ($subscales as $key => $sub) {
            $subscaleModels[$key] = Subscale::create([
                'exam_id' => $exam->id,
                'name' => $sub['name'],
                'name_ar' => $sub['name_ar'],
                'order' => $sub['order'],
            ]);
        }

        foreach ($bands as $i => $band) {
            SeverityBand::create([
                'exam_id' => $exam->id,
                'min_score' => $band['min'],
                'max_score' => $band['max'],
                'label' => $band['label'],
                'label_ar' => $band['label_ar'],
                'is_alert_worthy' => $band['alert'] ?? false,
                'order' => $i + 1,
            ]);
        }

        return $exam->fresh(['subscales']);
    }

    private function addQuestion(Exam $exam, Subscale $subscale, string $text, string $textAr, array $options, int $order): void
    {
        $question = Question::create([
            'exam_id' => $exam->id,
            'subscale_id' => $subscale->id,
            'text' => $text,
            'text_ar' => $textAr,
            'type' => 'single_select',
            'order' => $order,
        ]);

        foreach ($options as $i => $option) {
            QuestionOption::create([
                'question_id' => $question->id,
                'text' => $option[0],
                'text_ar' => $option[1],
                'score' => $option[2],
                'order' => $i + 1,
            ]);
        }
    }

    private function seedDavidsonTraumaScale(): void
    {
        $exam = $this->seedExam(
            [
                'name' => 'Post-Traumatic Stress Disorder (PTSD)',
                'name_ar' => 'اضطراب الكرب ما بعد الصدمة',
                'description' => 'اضطراب يظهر بعد تعرض الشخص لخبرة صادمة شديدة.',
            ],
            [
                'name' => 'Davidson Trauma Scale (DSM-IV)',
                'name_ar' => 'مقياس كرب ما بعد الصدمة لدافيسون (DSM-IV)',
                'description' => 'يتكون مقياس دافيسون لتقييم آثار الخبرات الصادمة من 17 بنداً تتناول الصفة التشخيصية الرابعة للطبيب النفسي الأمريكي. ويتم تقييم المقياس إلى ثلاثة مقاييس فرعية وهي: 1- استعادة الخبرة الصادمة وتشمل البنود 1,2,3,4,17. 2- تجنب الخبرة الصادمة وتشمل البنود 5,6,7,8,9,10,11. 3- الاستثارة وتشمل البنود 12,13,14,15,16. ويتم حساب النتيجة على مقياس مكون من 5 نقاط (من صفر إلى 4) ويكون سؤال المفصول عن الأعراض في الأسبوع المنصرم. التشخيص: عرض من أعراض استعادة الخبرة الصادمة، 3 أعراض من أعراض التجنب، عرض من أعراض الاستثارة.',
                'min_level_score' => null,
                'max_level_score' => 68,
            ],
            [
                'reexperiencing' => ['name' => 'Re-experiencing the trauma', 'name_ar' => 'استعادة الخبرة الصادمة', 'order' => 1],
                'avoidance' => ['name' => 'Avoidance of the trauma', 'name_ar' => 'تجنّب الخبرة الصادمة', 'order' => 2],
                'arousal' => ['name' => 'Hyperarousal', 'name_ar' => 'الاستثارة', 'order' => 3],
            ],
            []
        );

        $options = [
            ['Never', 'أبداً', 0],
            ['Rarely', 'نادراً', 1],
            ['Sometimes', 'أحياناً', 2],
            ['Often', 'غالباً', 3],
            ['Always', 'دائماً', 4],
        ];

        $items = [
            ['reexperiencing', 'Recurring thoughts, memories or images about the traumatic experience?', 'هل تحلم صور، وذكريات، وتفكّر عن الخبرة الصادمة؟'],
            ['reexperiencing', 'Recurring disturbing dreams related to the traumatic experience?', 'هل تحلم أحلام مزعجة تتعلق بالخبرة الصادمة؟'],
            ['reexperiencing', 'Sudden feelings, or that what happened to you will happen again?', 'هل تشعر بمشاعر فجائية أو خطرات بأن ما حدث لك سيحدث مرة أخرى؟'],
            ['reexperiencing', 'Do you avoid things that remind you of what you went through?', 'هل تتجنب من الأشياء التي تذكرك بما تعرضت له من خبرة صادمة؟'],
            ['avoidance', 'Do you avoid thoughts or feelings that remind you of the traumatic event?', 'هل تتجنب الأفكار أو المشاعر التي تذكرك بالحادث الصادم؟'],
            ['avoidance', 'Do you avoid situations and things that remind you of the traumatic event?', 'هل تتجنب المواقف والأشياء التي تذكرك بالحادث الصادم؟'],
            ['avoidance', 'Do you suffer from memory loss for the traumatic events you experienced (specific dissociative amnesia)?', 'هل تعاني من فقدان الذاكرة للأحداث الصادمة التي تعرضت لها (فقدان ذاكرة نسي محدد)'],
            ['avoidance', 'Do you have difficulty enjoying your life and the daily activities you are used to?', 'هل لديك صعوبة في التمتع بحياتك والانشغالات اليومية التي تعودت عليها؟'],
            ['avoidance', 'Do you feel isolated and distant, and unable to feel love towards others or to socialize?', 'هل تشعر بالعزلة وبأنك بعيد ولا تشعر بالحب تجاه الآخرين أو الانبساط؟'],
            ['avoidance', 'Have you lost the feeling of sadness and love (that you have become emotionally numbed)?', 'هل فقدت الشعور بالحزن والحب (أنك ميتدئ الإحساس)؟'],
            ['avoidance', 'Do you find it hard to imagine living long enough to achieve your goals in work, marriage and having children?', 'هل تجد صعوبة في تخيّل بقائك على قيد الحياة لفترة طويلة لتحقيق أهدافك في العمل، والزواج، وإنجاب الأطفال؟'],
            ['arousal', 'Do you have difficulty sleeping or staying asleep?', 'هل لديك صعوبة في النوم أو البقاء ناعما؟'],
            ['arousal', 'Are you afflicted by episodes of tension and anger?', 'هل تنتابك نوبات من التوتر و الغضب؟'],
            ['arousal', 'Do you have difficulty concentrating?', 'هل تعاني من صعوبات في التركيز؟'],
            ['arousal', 'Do you feel on the verge of collapse, and is your attention easily distracted?', 'هل تشعر بأنك على حافة الانهيار (واضحة ملامح على الأخر)، ومن السهل تشتّيت انتباهك؟'],
            ['arousal', 'Are you easily provoked, and always feel on edge and expecting the worst?', 'هل تستثار لأقلة الأسباب وتشعر دائما بأنك متحفز و متوقع الأسوأ؟'],
            ['reexperiencing', 'Do things and people that remind you of the traumatic experience cause episodes of shortness of breath, shaking, heavy sweating and a rapid heartbeat?', 'هل الأشياء والأشخاص الذين يذكرونك بالخبرة الصادمة يجعلك تعاني من نوبة من ضيق النفس، والرعشة، والعرق الغزير وسرعة في ضربات قلب؟'],
        ];

        foreach ($items as $i => $item) {
            $this->addQuestion($exam, $exam->subscales[$item[0]], $item[1], $item[2], $options, $i + 1);
        }
    }

    private function seedGad7(): void
    {
        $exam = $this->seedExam(
            [
                'name' => 'Generalized Anxiety Disorder (GAD)',
                'name_ar' => 'اضطراب القلق العام',
                'description' => 'اضطراب قلق عام مستمر يصعب السيطرة عليه.',
            ],
            [
                'name' => 'Generalized Anxiety Disorder-7 (GAD-7)',
                'name_ar' => 'اختبار ضغط القلق العام GAD-7',
                'description' => 'في الأسبوع عن الآخرين كم زعجتك المشاكل التالية:',
                'min_level_score' => null,
                'max_level_score' => 21,
            ],
            [
                'general' => ['name' => 'General Anxiety', 'name_ar' => 'القلق العام', 'order' => 1],
            ],
            [
                ['min' => 0, 'max' => 5, 'label' => 'Slight anxiety', 'label_ar' => 'قلق طفيف'],
                ['min' => 6, 'max' => 10, 'label' => 'Mild anxiety', 'label_ar' => 'قلق بسيط'],
                ['min' => 11, 'max' => 15, 'label' => 'Moderate anxiety', 'label_ar' => 'قلق متوسط'],
                ['min' => 16, 'max' => 21, 'label' => 'Severe anxiety', 'label_ar' => 'قلق شديد', 'alert' => true],
            ]
        );

        $options = [
            ['Not at all', 'أبدا', 0],
            ['Several days', 'بعض الأيام', 1],
            ['More than half the days', 'أكثر من نصف الأيام', 2],
            ['Nearly every day', 'تقريبا كل يوم', 3],
        ];

        $items = [
            ['Feeling nervous, anxious or on edge', 'الشعور بالعصبية أو التوتر أو الانزعاج'],
            ['Not being able to stop or control worrying', 'عدم القدرة على التوقف عن القلق أو السيطرة عليه'],
            ['Worrying too much about different things', 'القلق الشديد بشأن أمور عديدة'],
            ['Trouble relaxing', 'مشاكل في الاسترخاء'],
            ['Being so restless that it is hard to sit still', 'أن تكون مضطربا لدرجة تجعلك غير قادر على الجلوس بهدوء'],
            ['Becoming easily annoyed or irritable', 'نشأة أو تفضيل بسرعة'],
            ['Feeling afraid as if something awful might happen', 'تشعر بالخوف من حدوث شيء رهيب'],
        ];

        foreach ($items as $i => $item) {
            $this->addQuestion($exam, $exam->subscales['general'], $item[0], $item[1], $options, $i + 1);
        }
    }

    private function seedYbocs(): void
    {
        $exam = $this->seedExam(
            [
                'name' => 'Obsessive-Compulsive Disorder (OCD)',
                'name_ar' => 'اضطراب الوسواس القهري',
                'description' => 'اضطراب يتميز بوجود أفكار وسواسية وأفعال قهرية متكررة.',
            ],
            [
                'name' => 'Yale-Brown Obsessive Compulsive Scale (Y-BOCS)',
                'name_ar' => 'مقياس ييل براون للوسواس القهري',
                'description' => 'يرجى .. ضع علامة ( ✓ ) على بيمن الجملة التي توضح معدل حدوث كل بنود خلال السبعة أيام الماضية بما فيها وقت هذه الجلسة.',
                'min_level_score' => null,
                'max_level_score' => 40,
            ],
            [
                'obsessions' => ['name' => 'Obsessive thoughts', 'name_ar' => 'الأفكار الوسواسية', 'order' => 1],
                'compulsions' => ['name' => 'Compulsive behaviors', 'name_ar' => 'الأفعال القهرية', 'order' => 2],
            ],
            [
                ['min' => 0, 'max' => 7, 'label' => 'Subclinical', 'label_ar' => 'خفيف جدا'],
                ['min' => 8, 'max' => 15, 'label' => 'Mild', 'label_ar' => 'خفيف'],
                ['min' => 16, 'max' => 23, 'label' => 'Moderate', 'label_ar' => 'متوسط'],
                ['min' => 24, 'max' => 31, 'label' => 'Severe', 'label_ar' => 'ملحوظ'],
                ['min' => 32, 'max' => 40, 'label' => 'Extreme', 'label_ar' => 'شديد', 'alert' => true],
            ]
        );

        $items = [
            [
                'obsessions',
                'Amount of time spent occupied by obsessive thoughts',
                'مقدار الوقت الذي تستغرقه الأفكار الوسواسية',
                [
                    ['None', 'لا شيء', 0],
                    ['Less than an hour a day, or occasional episodes (averaging 8 times a day)', 'أقل من ساعة في اليوم ، أو تتذكر أحيانا (بمعدل 8 مرات فقليما)', 1],
                    ['One to three hours a day, or frequent episodes (more than 8 times a day but most hours are free of obsessions)', 'من ساعة إلى 3 ساعات في اليوم ، أو تتذكر كثيرا (أكثر من 8 مرات في اليوم لكن معظم ساعات اليوم خالية من الأفكار الوسواسية)', 2],
                    ['Three to eight hours a day, or very frequent episodes (more than 8 times a day and during most hours of the day)', 'من 3 إلي 8 ساعات في اليوم ، أو تحدثت كثيرا جدا (تحدثت أكثر من 8 مرات في اليوم وفي معظم ساعات اليوم)', 3],
                    ['More than 8 hours a day, or near-constant episodes (more than can be tolerated, rarely an hour without many obsessions)', 'أكثر من 8 ساعات في اليوم ، أو تحدث بشكل دائم (أكثر من تحملها ونادرا ما متأمر ساعة بدون وسواس كثيرة)', 4],
                ],
            ],
            [
                'obsessions',
                'Interference due to obsessive thoughts',
                'مقدار التعارض الذي تحدثه الأفكار الوسواسية مع نشاطاتك الإجتماعية والعملية.',
                [
                    ['None', 'لا يوجد', 0],
                    ['Slight interference with social or work activities, but overall performance is not impaired', 'تعارض خفيف مع النشاطات الإجتماعية أو العملية ، لكن النشاط العام لا يتأثر', 1],
                    ['Clear interference with social or work activities, but can still be managed', 'تعارض واضح في النشاطات الإجتماعية أو العملية ، لكن يمكن السيطرة عليه', 2],
                    ['Causes considerable interference with social or work activities', 'تسبب خلاا كبيرا في أداء النشاطات الإجتماعية أو العملية', 3],
                    ['Causes incapacitating interference', 'تسبب خلاا بليغا', 4],
                ],
            ],
            [
                'obsessions',
                'Distress and anxiety caused by obsessive thoughts',
                'مقدار الألم أو القلق المصاحب للأفكار الوسواسية (بغض النظر عن نجاحك في مقاومتها)',
                [
                    ['None', 'لا يوجد', 0],
                    ['Mild (occasional), not distressing', 'خفيف (أحيانا) ، وليس مزعجا', 1],
                    ['Moderate (frequent) and distressing but can still be managed', 'متوسط (غالبا) ومزعج ولكن يمكن السيطرة عليه', 2],
                    ['Severe (most of the time) and very distressing', 'شديد (أغلي الوقت) ومزعج جدا', 3],
                    ['Extreme (constant) distress, to the point of being nearly incapacitating', 'توتر بليغ (دايما) لحد الإعاقة تقريبا', 4],
                ],
            ],
            [
                'obsessions',
                'Resistance against obsessive thoughts',
                'مقدار الجهود الذي المبذول في مقاومة الأفكار الوسواسية (بمض النظر عن نجاحك في المقاومة)',
                [
                    ['I make no effort to resist (or the obsessions are so mild that no resistance is needed)', 'لا أبذل جهد حتى أقاوم دائما (أو أن الأفكار قابلة جدا بحيث لا حاجة لمقاومة)', 0],
                    ['I try to resist most of the time', 'أحاول أن أقاوم معظم الوقت', 1],
                    ['I make some effort to resist', 'أبذل بعض الجهد حتى أقاوم', 2],
                    ['I yield to all obsessions without attempting to control them, and any control is reluctant', 'أستسلم لكل الأفكار الوسواسية بدون محاولة السيطرة عليها ، وإن حاولت السيطرة فبكون بعد تردد', 3],
                    ['I yield completely and automatically to all obsessions', 'أستسلم كلية وببادئي للأفكار الوسواسية كلها', 4],
                ],
            ],
            [
                'obsessions',
                'Control over obsessive thoughts',
                'مقدار سيطرتك على الأفكار الوسواسية',
                [
                    ['Complete control', 'سيطرة تامة', 0],
                    ['Good control, with effort and concentration I can usually stop or distract myself from the obsession', 'سيطرة كبيرة، عادة يمنعني أن أوقف أو أصرف انتباهي عن الوسواس عند بعض الجهد والتركيز', 1],
                    ['Moderate control, sometimes I can stop or control the obsession', 'سيطرة متوسطة، ببعض الأحيان استطيع إيقاف أو السيطرة عليه', 2],
                    ['Little control, rarely able to stop the obsession, and can only distract myself with difficulty', 'سيطرة قليلة، نادرا ما أنجح في إيقاف الوسواس ، أستطيع فقط صرف الانتباه وبصعوبة', 3],
                    ['No control, rarely able to divert attention from the obsession even for brief moments', 'لا سيطرة ، نادرا ما أستطيع صرف الانتباه عن الوسواس ولو للحظات قليلة', 4],
                ],
            ],
            [
                'compulsions',
                'Amount of time spent performing compulsive behaviors',
                'مقدار الوقت الذي تقضيه في القيام بالأفعال القهرية',
                [
                    ['None', 'لا شيء', 0],
                    ['Less than an hour a day, or occasional performance (not more than 8 times a day)', 'أقل من ساعة في اليوم ، أو تقوم بالأفعال أحيانا ( لا تزيد عن 8 مرات في اليوم)', 1],
                    ['One to three hours a day, or frequent performance (more than 8 times a day but most hours are free of compulsions)', 'من ساعة إلي ثلاث ساعات في اليوم ، أو تقوم بالأفعال كثيرا ( أكثر من 8 مرات في اليوم ولكن معظم الساعات تخلو من الأفعال القهرية)', 2],
                    ['More than three hours a day, or very frequent performance (more than 8 times a day and during most hours of the day)', 'أكثر من ثلاث ساعات في اليوم ، أو تقوم بالأفعال كثيرا جدا ( أكثر من 8 مرات في اليوم وخلال معظم الساعات اليوم)', 3],
                    ['More than 8 hours a day, or near-constant performance (more than can be tolerated, rarely an hour without performing the behaviors)', 'أكثر من 8 ساعات في اليوم أو تقوم بالأفعال بشكل دائم (أكثر من تحمّلها ونادرا ما متأمر ساعة لا تقوم فيها بالأفعال)', 4],
                ],
            ],
            [
                'compulsions',
                'Interference due to compulsive behaviors',
                'مقدار التعارض الذي تحدثه الأفعال القهرية في نشاطاتك الإجتماعي والعملية',
                [
                    ['None', 'لا يوجد', 0],
                    ['Slight interference with social or work activities, but overall performance is not impaired', 'تعارض خفيف مع النشاطات الإجتماعية أو العملية ، لكن النشاط العام لا يتأثر', 1],
                    ['Clear interference with social or work activities, but can still be managed', 'تعارض واضح مع النشاطات الإجتماعية أو العملية ، ويمكن السيطرة عليه', 2],
                    ['Causes considerable interference with social or work activities', 'تسبب خلاا كبيرا في أداء النشاطات الإجتماعية أو العملية', 3],
                    ['Causes severe incapacity', 'تسبب عجزا كبيرا', 4],
                ],
            ],
            [
                'compulsions',
                'Distress and anxiety if prevented from performing compulsive behaviors',
                'مقدار التوتر والقلق الناتج في حال الامتناع عن القيام بالأفعال القهرية',
                [
                    ['None', 'لا يوجد', 0],
                    ['Anxiety relieved by performing the compulsive behaviors', 'قلق يسند عند الامتناع عن القيام بالأفعال', 1],
                    ['Anxiety appears but can still be tolerated', 'يظهر القلق لكن يمكن تحمله', 2],
                    ['Marked and very distressing anxiety', 'قلق واضح ومزعج للغاية', 3],
                    ['Severe anxiety because of incapacitation', 'قلق شديد بسبب عجزا بلغا', 4],
                ],
            ],
            [
                'compulsions',
                'Resistance against compulsive behaviors',
                'مقدار الجهود الذي المبذول في مقاومة الأفعال القهرية (بمض النظر عن مدى نجاحك في المقاومة)',
                [
                    ['I make no effort to resist (or the behaviors are so mild that no resistance is needed)', 'لا أبذل جهد حتى أقاوم دائما (أو أن الأفعال القهرية قليلة جدا بحيث لا حاجة للمقاومة)', 0],
                    ['I try to resist most of the time', 'أحاول أن أقاوم معظم الوقت', 1],
                    ['I make some attempts to resist', 'أفعل بعض المحاولات لات المقاومة', 2],
                    ['I yield to all compulsive behaviors without attempting to control them, and any control is reluctant', 'أستسلم للأفعال القهرية بدون محاولة السيطرة عليها وإن حاولت السيطرة فبكون بعد تردد', 3],
                    ['I yield completely and automatically to all compulsive behaviors', 'أستسلم كلية وببادئي لكل الأفعال القهرية', 4],
                ],
            ],
            [
                'compulsions',
                'Control over compulsive behaviors',
                'مقدار سيطرتك على الأفعال القهرية',
                [
                    ['Complete control', 'سيطرة تامة', 0],
                    ['I can usually stop the compulsive behaviors easily', 'عادة ما أوقف الأفعال القهرية بسهولة', 1],
                    ['Sometimes I can stop the compulsive behaviors easily', 'أحيانا أستطيع إيقاف الأفعال القهرية بسهولة', 2],
                    ['I can stop the compulsive behaviors only with difficulty, but I have to complete them', 'أستطيع بسهولة أن أوقف - فقط - الأفعال القهرية لكن يجب علي القيام بها حتى النهاية', 3],
                    ['Rarely able to stop or delay the compulsive behaviors even for brief moments', 'نادرا ما أستطيع أن أوقف أن أخر القيام بالأفعال القهرية ولو للحظات', 4],
                ],
            ],
        ];

        foreach ($items as $i => $item) {
            $this->addQuestion($exam, $exam->subscales[$item[0]], $item[1], $item[2], $item[3], $i + 1);
        }
    }
}
