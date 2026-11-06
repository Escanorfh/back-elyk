<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * صفّ واحد لكل نداء cron خارجي، يُكتب قبل أي عمل حتى يبقى الموت في منتصف الدورة مرئيًا.
 *
 * لا created_at/updated_at هنا عمدًا: run_at هو الحقيقة الوحيدة ومرشّح الاحتفاظ لسبعة أيام يقرأه،
 * فعمود ثانٍ لنفس المعنى مصدر حقيقة ثانٍ.
 */
class SchedulerRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_at', 'batch_id', 'due_considered', 'sent', 'failed', 'max_lateness_seconds', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'due_considered' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'max_lateness_seconds' => 'integer',
            'run_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function scopeSince(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('run_at', '>=', $moment);
    }

    /** آخر دورة معروفة — مصدر صحة المُجدوَل في واجهة الإدارة. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('run_at');
    }
}
