<?php

namespace App\Models;

use App\Enums\PersonalTaskStatus;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * مهمة بلا خطوة علاجية — شخصية أو متابعة من المعالج. الواجب العلاجي يبقى في path_steps.
 *
 * due_at الفارغ = قسم «بلا تاريخ» ولا يعني «اليوم»؛ والمتأخر محسوب من (due_at, status, now)
 * في App\Support\CalendarTime ولا يُخزَّن كحالة.
 */
class PersonalTask extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description', 'category', 'due_at',
        'timezone', 'priority', 'status', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_by' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** نفس شكل /calendar تمامًا (إزاحة صريحة و UTC) — لا صيغتان لعمود واحد. */
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

    /** due_at لحظة داخل [from, to)؛ بلا تاريخ لا يدخل أي نافذة إطلاقًا. */
    public function scopeInRange(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return CalendarTime::whereOverlaps($query, 'due_at', null, $from, $to);
    }

    /** ما لم يُغلق بعد: هو المرشّح الوحيد لسلتي overdue و today. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [PersonalTaskStatus::Pending->value]);
    }

    public function isEditableBy(int $userId): bool
    {
        if ((int) $this->user_id !== $userId) {
            return false;
        }

        return $this->created_by === null || (int) $this->created_by === $userId;
    }
}
