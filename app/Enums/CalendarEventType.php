<?php

namespace App\Enums;

/** calendar_events.type — أحداث غير علاجية فقط (الجلسات والواجبات تبقى في جداولها). */
enum CalendarEventType: string
{
    use HasValues;

    case PersonalReminder = 'personal_reminder';
    case ImportantDay = 'important_day';
    case OtherClinicalAppointment = 'other_clinical_appointment';
}
