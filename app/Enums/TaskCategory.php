<?php

namespace App\Enums;

/**
 * تصنيف المهمة — نفس الفضاء لـ personal_tasks و path_steps، لكن لكل جدول مجموعة مقبولة
 * مختلفة (الخطوة العلاجية لا تكون personal_task والمهمة الشخصية لا تكون therapeutic_homework).
 */
enum TaskCategory: string
{
    use HasValues;

    case PersonalTask = 'personal_task';
    case TherapeuticHomework = 'therapeutic_homework';
    case RelaxationExercise = 'relaxation_exercise';
    case DailyPractice = 'daily_practice';
    case TherapistFollowup = 'therapist_followup';

    /** @return list<string> */
    public static function forPersonalTask(): array
    {
        return [
            self::PersonalTask->value,
            self::RelaxationExercise->value,
            self::DailyPractice->value,
            self::TherapistFollowup->value,
        ];
    }

    /** @return list<string> */
    public static function forPathStep(): array
    {
        return [
            self::TherapeuticHomework->value,
            self::RelaxationExercise->value,
            self::DailyPractice->value,
            self::TherapistFollowup->value,
        ];
    }
}
