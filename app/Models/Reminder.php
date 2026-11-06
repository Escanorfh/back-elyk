<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * تذكير مجدوَل = لحظة UTC مطلقة واحدة لـ (عنصر، إزاحة). يحسبها المُنشئ عند الكتابة ولا تُعاد
 * حسبتها عند العرض؛ والتوقيت الأصلي يبقى في timezone كمسار تدقيق فقط.
 *
 * إعادة الجدولة تلغي الصفوف وتُدخل جديدة بدل تعديل التاريخ، لذلك reminds_at جزء من الهوية
 * الفريدة: دورة جديدة يجب ألا تصطدم بالدورة الملغاة.
 */
class Reminder extends Model
{
    protected $fillable = [
        'notifiable_type', 'notifiable_id', 'audience', 'offset_minutes', 'reminds_at',
        'timezone', 'channel', 'status', 'claimed_at', 'attempts', 'last_error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'notifiable_id' => 'integer',
            'offset_minutes' => 'integer',
            'attempts' => 'integer',
            'reminds_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReminderDelivery::class);
    }

    /** الحلال في دورة المعالج: لم تُرسل ولم تُطالب بها جولة أخرى. */
    public function scopeDue(Builder $query, \DateTimeInterface $now): Builder
    {
        return $query
            ->where('status', ReminderStatus::Pending->value)
            ->where('reminds_at', '<=', $now);
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query
            ->where('notifiable_type', $owner->getMorphClass())
            ->where('notifiable_id', $owner->getKey());
    }
}
