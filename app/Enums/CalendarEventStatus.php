<?php

namespace App\Enums;

enum CalendarEventStatus: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public const UPCOMING = ['scheduled', 'confirmed'];

    public function isUpcoming(): bool
    {
        return in_array($this->value, self::UPCOMING, true);
    }
}
