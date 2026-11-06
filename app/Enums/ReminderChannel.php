<?php

namespace App\Enums;

/**
 * قنوات الإرسال. القرار المعتمد (B=A): في التطبيق + إشعار محلي فقط؛ FCM مؤجَّل ولا يُوعَد المستخدم
 * بتوصيل غير مضمون. 'auto' = يتركها الخادم يقرر حسب إعدادات المستخدم.
 */
enum ReminderChannel: string
{
    use HasValues;

    case Auto = 'auto';
    case InApp = 'in_app';
    case LocalNotification = 'local_notification';

    /** @return list<string> */
    public static function writable(): array
    {
        return [self::InApp->value, self::LocalNotification->value];
    }
}
