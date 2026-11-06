<?php

namespace App\Enums;

enum Priority: string
{
    use HasValues;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
