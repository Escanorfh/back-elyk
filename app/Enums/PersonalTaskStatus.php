<?php

namespace App\Enums;

enum PersonalTaskStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
