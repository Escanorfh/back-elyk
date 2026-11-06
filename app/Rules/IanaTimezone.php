<?php

namespace App\Rules;

use App\Support\CalendarTime;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * منطقة IANA فقط: الاختصارات ('EST') والإزاحات ('+02:00') لا تحمل قواعد التوقيت الصيفي،
 * فتُرفض بدل أن تُخزَّن وتُفسد حساب يوم محلي بعد شهور.
 */
class IanaTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CalendarTime::isValidTimezone(is_string($value) ? $value : null)) {
            $fail("The {$attribute} must be a valid IANA timezone identifier, e.g. Africa/Cairo.");
        }
    }
}
