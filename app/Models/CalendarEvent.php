<?php

namespace App\Models;

use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * حدث شخصي لا علاقة له بالعلاج: تذكير، يوم مهم، موعد إكلينيكي آخر.
 *
 * الجلسات تبقى في live_sessions والواجبات في path_steps؛ التقويم طبقة تجميع فوق الجداول الأربعة،
 * ولا يوجد جدول calendar_items ولا صف مكرر.
 * created_by فارغ = المريض أنشأه بنفسه؛ قابلية التعديل تُشتق منه ولا تُخزَّن كعلامة ثانية.
 */
class CalendarEvent extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description', 'type',
        'starts_at', 'ends_at', 'all_day', 'timezone',
        'location', 'priority', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_by' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    /**
     * شكل واحد لكل تواريخ التقويم: خدمة التجميع تُصدر إزاحة صريحة (+00:00)، فلا يصير لنفس
     * الحقل صيغتان بين /calendar و /events. القيم تبقى UTC دائمًا.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone('UTC')->toIso8601String();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'notifiable');
    }

    /** عناصر تتقاطع مع [from, to) — القاعدة نفسها في App\Support\CalendarTime::whereOverlaps. */
    public function scopeInRange(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return CalendarTime::whereOverlaps($query, 'starts_at', 'ends_at', $from, $to);
    }

    /** المريض لا يعدّل عنصرًا أنشأه المعالج؛ العنصر المملوك لغيره غير قابل حتى للقراءة. */
    public function isEditableBy(int $userId): bool
    {
        if ((int) $this->user_id !== $userId) {
            return false;
        }

        return $this->created_by === null || (int) $this->created_by === $userId;
    }
}
