<?php

namespace App\Enums;

/**
 * حالة تذكير أو سطر تسليم واحد. العمود varchar(16) في الجدولين، فلا migration يلزم لإضافة حالة.
 *
 * skipped ليست failed: التفضيل المُعطَّل أو انعدام المستقبِل قرار صائب لا يُعاد، أما failed فسببها
 * عطل يستدعي إعادة محاولة. خلطهما يجعل إعادة المحاولة تُعيد أشياءَ مرفودة عمدًا.
 */
enum ReminderStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
