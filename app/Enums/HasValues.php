<?php

namespace App\Enums;

trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function contains(?string $value): bool
    {
        return $value !== null && in_array($value, self::values(), true);
    }
}
