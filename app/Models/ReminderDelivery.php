<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ الإرسال، والشيء الوحيد الذي يجعل إعادة المحاولة آمنة.
 *
 * القيد الفريد المركّب (reminder_id, user_id, channel, target_key) هو حارس عدم التكرار: الإدراج والإرسال
 * قرار واحد، فتكرار الإدراج يعني «أُرسلت فعلًا» لا إشعارًا ثانيًا. target_key يولّده الخادم وحده.
 */
class ReminderDelivery extends Model
{
    protected $fillable = [
        'reminder_id', 'user_id', 'channel', 'target_key', 'status', 'provider_message_id',
        'error', 'attempts', 'next_retry_at', 'attempted_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeRetryable(Builder $query, \DateTimeInterface $now): Builder
    {
        return $query
            ->where('status', ReminderStatus::Failed->value)
            ->where(fn (Builder $inner) => $inner->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now));
    }
}
