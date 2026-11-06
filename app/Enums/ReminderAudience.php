<?php

namespace App\Enums;

/**
 * من يُشعَر: المشاركون في جلسة، أو صاحب العنصر وحده في المهمة/الحدث الشخصي.
 */
enum ReminderAudience: string
{
    use HasValues;

    case Participants = 'participants';
    case Owner = 'owner';
}
