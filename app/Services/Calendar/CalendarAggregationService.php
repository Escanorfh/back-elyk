<?php

namespace App\Services\Calendar;

use App\Enums\PersonalTaskStatus;
use App\Enums\ReminderStatus;
use App\Enums\SessionStatus;
use App\Models\AppSetting;
use App\Models\CalendarEvent;
use App\Models\LiveSession;
use App\Models\PathStep;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * التقويم طبقة تجميع فوق الجداول الأربعة: لا جدول calendar_items ولا صف مكرر — كل عنصر يُقرأ من
 * مصدره ويُحوَّل إلى CalendarItem واحد، والمتأخر والسلّة محسوبان من App\Support\CalendarTime.
 *
 * النطاق يُبنى من منطقة المستخدم المعلَنة (أو ?timezone من العميل عند العرض)؛ أما تقسيم «اليوم»
 * للواجهة فيقرّره تقويم الجهاز. الاستعلام محدود بسقف calendar.range_max_items لكل مصدر، والتذكير
 * يُقرأ في استعلام واحد لكل مصدر — لا N+1 في شاشة تُفتح كل يوم.
 */
class CalendarAggregationService
{
    public const SOURCES = ['live_session', 'path_step', 'personal_task', 'calendar_event'];

    public const MAX_ITEMS_DEFAULT = 500;

    /** ترتيب العنصر بلا لحظة: بعد كل ما له تاريخ، وقبل لا شيء. */
    private const NO_DATE_SENTINEL = '9999-12-31T23:59:59+00:00';

    private ?int $cap = null;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{range: array<string, string>, items: list<array<string, mixed>>, counts: array<string, int>, truncated: bool}
     */
    public function forPatient(User $patient, array $filters): array
    {
        $range = $this->range($filters, $patient);
        $timezone = $range['timezone'];
        $now = CarbonImmutable::now('UTC');
        $sources = $this->sources($filters);

        return $this->package($this->collect($patient, $sources, $range, $filters, $timezone, $now, asPatient: true), $range, $sources);
    }

    /**
     * رؤية الإدارة: جلسات هذا الأدمن + نشاط المرضى. تحديد patient_id يحصر كل المصادر في مريض واحد.
     *
     * @param  array<string, mixed>  $filters
     * @return array{range: array<string, string>, items: list<array<string, mixed>>, counts: array<string, int>, truncated: bool}
     */
    public function forAdmin(User $admin, array $filters): array
    {
        $target = ($filters['patient_id'] ?? '') !== '' && isset($filters['patient_id'])
            ? User::query()->whereKey((int) $filters['patient_id'])->where('role', 'patient')->firstOrFail()
            : null;

        $scope = $target ?? $admin;
        $range = $this->range($filters, $scope);
        $timezone = $range['timezone'];
        $now = CarbonImmutable::now('UTC');
        $sources = $this->sources($filters);

        return $this->package(
            $this->collect($scope, $sources, $range, $filters, $timezone, $now, asPatient: $target !== null),
            $range,
            $sources
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $range
     * @return Collection<int, array<string, mixed>>
     */
    private function collect(User $scope, array $sources, array $range, array $filters, string $timezone, CarbonImmutable $now, bool $asPatient): Collection
    {
        $items = collect();

        foreach ($sources as $source) {
            $items = $items->merge(match ($source) {
                'live_session' => $asPatient
                    ? $this->sessions($scope, $range, $filters, $timezone, $now, fn (Builder $q) => $q->visibleToPatient($scope->id))
                    : $this->sessions($scope, $range, $filters, $timezone, $now, fn (Builder $q) => $q->where('admin_id', $scope->id)),
                'path_step' => $this->steps($scope, $range, $filters, $timezone, $now, $asPatient),
                'personal_task' => $this->tasks($scope, $range, $filters, $timezone, $now, $asPatient),
                'calendar_event' => $this->events($scope, $range, $filters, $timezone, $now, $asPatient),
            });
        }

        return $items;
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $range
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sessions(User $actor, array $range, array $filters, string $timezone, CarbonImmutable $now, callable $ownership): Collection
    {
        $query = LiveSession::query()
            ->tap($ownership)
            ->scheduledInRange($range['from'], $range['to'])
            ->withCount('invites')
            ->limit($this->cap() + 1);

        $this->applyStatusAndPriority($query, $filters);
        $this->applySearch($query, $filters);

        $models = $query->get();
        $reminders = $this->reminderMap($models);

        return $models->map(function (LiveSession $session) use ($actor, $timezone, $now, $reminders): array {
            $start = $this->utc($session->scheduled_at);

            return $this->item(
                source: 'live_session',
                id: (int) $session->getKey(),
                kind: $session->patient_id === null ? 'group_session' : 'individual_session',
                title: (string) $session->title,
                description: $session->description,
                start: $start,
                end: $this->utc($session->ends_at),
                allDay: false,
                timezone: CalendarTime::timezoneOf($session->timezone),
                status: (string) $session->status,
                priority: (string) $session->priority,
                location: $session->location,
                bucket: $this->phase($start, (string) $session->status, $timezone, $now),
                overdue: false,
                participantCount: (int) ($session->invites_count ?: ($session->patient_id === null ? 0 : 1)),
                editableByUser: $actor->role === 'admin' && $actor->id === $session->admin_id,
                reminderOffsets: $reminders[$session->getKey()] ?? [],
            );
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function steps(User $actor, array $range, array $filters, string $timezone, CarbonImmutable $now, bool $asPatient): Collection
    {
        $query = PathStep::query()
            ->dueInRange($range['from'], $range['to'])
            ->limit($this->cap() + 1);

        if ($asPatient) {
            // خطوة المريض: الوصول عبر مسار المريض نفسه — لا patient_id على path_steps.
            $query->whereHas('path', fn (Builder $p) => $p->where('patient_id', $actor->id));
        }

        $this->applyStatusAndPriority($query, $filters);
        $this->applySearch($query, $filters);

        $models = $query->get();
        $reminders = $this->reminderMap($models);

        return $models->map(function (PathStep $step) use ($actor, $timezone, $now, $asPatient, $reminders): array {
            $due = $this->utc($step->due_at);
            $status = (string) $step->status;

            return $this->item(
                source: 'path_step',
                id: (int) $step->getKey(),
                kind: 'homework',
                title: (string) $step->title,
                description: $step->description,
                start: $due,
                end: null,
                allDay: false,
                timezone: CalendarTime::timezoneOf($step->due_timezone),
                status: $status,
                priority: (string) $step->priority,
                location: null,
                bucket: CalendarTime::bucket($due, $status, $now, $timezone),
                overdue: CalendarTime::isOverdue($due, $status, $now),
                participantCount: null,
                // الخطوة العلاجية للمريض للقراءة فقط؛ يعدها المعالج أو يسلّم عليها المريض وحده.
                editableByUser: $asPatient ? false : $actor->role === 'admin',
                reminderOffsets: $reminders[$step->getKey()] ?? [],
            );
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function tasks(User $actor, array $range, array $filters, string $timezone, CarbonImmutable $now, bool $asPatient): Collection
    {
        $query = PersonalTask::query()
            ->inRange($range['from'], $range['to'])
            ->when($asPatient, fn (Builder $q) => $q->where('user_id', $actor->id))
            ->when(! $asPatient, fn (Builder $q) => $q->whereIn('user_id', User::query()->where('role', 'patient')->select('id')))
            ->limit($this->cap() + 1);

        $this->applyStatusAndPriority($query, $filters);
        $this->applySearch($query, $filters);

        $models = $query->get();
        $reminders = $this->reminderMap($models);

        return $models->map(function (PersonalTask $task) use ($actor, $timezone, $now, $asPatient, $reminders): array {
            $due = $this->utc($task->due_at);
            $status = (string) $task->status;

            return $this->item(
                source: 'personal_task',
                id: (int) $task->getKey(),
                kind: (string) $task->category,
                title: (string) $task->title,
                description: $task->description,
                start: $due,
                end: null,
                allDay: false,
                timezone: CalendarTime::timezoneOf($task->timezone),
                status: $status,
                priority: (string) $task->priority,
                location: null,
                bucket: CalendarTime::bucket($due, $status, $now, $timezone),
                overdue: CalendarTime::isOverdue($due, $status, $now),
                participantCount: null,
                editableByUser: $asPatient ? $task->isEditableBy($actor->id) : $actor->role === 'admin',
                reminderOffsets: $reminders[$task->getKey()] ?? [],
            );
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function events(User $actor, array $range, array $filters, string $timezone, CarbonImmutable $now, bool $asPatient): Collection
    {
        $query = CalendarEvent::query()
            ->inRange($range['from'], $range['to'])
            ->when($asPatient, fn (Builder $q) => $q->where('user_id', $actor->id))
            ->when(! $asPatient, fn (Builder $q) => $q->whereIn('user_id', User::query()->where('role', 'patient')->select('id')))
            ->limit($this->cap() + 1);

        $this->applyStatusAndPriority($query, $filters);
        $this->applySearch($query, $filters);

        $models = $query->get();
        $reminders = $this->reminderMap($models);

        return $models->map(function (CalendarEvent $event) use ($actor, $timezone, $now, $asPatient, $reminders): array {
            $start = $this->utc($event->starts_at);

            return $this->item(
                source: 'calendar_event',
                id: (int) $event->getKey(),
                kind: (string) $event->type,
                title: (string) $event->title,
                description: $event->description,
                start: $start,
                end: $this->utc($event->ends_at),
                allDay: (bool) $event->all_day,
                timezone: CalendarTime::timezoneOf($event->timezone),
                status: (string) $event->status,
                priority: (string) $event->priority,
                location: $event->location,
                bucket: $this->phase($start, (string) $event->status, $timezone, $now),
                overdue: false,
                participantCount: null,
                editableByUser: $asPatient ? $event->isEditableBy($actor->id) : $actor->role === 'admin',
                reminderOffsets: $reminders[$event->getKey()] ?? [],
            );
        });
    }

    /**
     * صياغة لحظة العنصر بـ CarbonImmutable بعد UTC: الصبغة تُعطي Carbon متغيّرًا، وكل حساب
     * التقويم يمر عبر CarbonImmutable حتى لا يتغيّر صفيف وهو مرتّب.
     */
    private function utc(?\DateTimeInterface $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone('UTC');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, timezone: string}  $range
     * @param  list<string>  $sources
     * @return array{range: array<string, string>, items: list<array<string, mixed>>, counts: array<string, int>, truncated: bool}
     */
    private function package(Collection $items, array $range, array $sources): array
    {
        $cap = $this->cap();

        // بلا تاريخ يقف في آخر القائمة: لا صفر ولا «اليوم» — التاريخ الفارغ ليس لحظة قديمة.
        $ordered = $items
            ->sortBy(fn (array $item): array => [$item['starts_at'] ?? self::NO_DATE_SENTINEL, $item['source'], $item['id']])
            ->values();

        // كل مصدر مطلوب يظهر في counts ولو بصفر: الواجهة لا تتعامل مع مفاتيح ناقصة.
        $counts = array_merge(
            array_fill_keys($sources, 0),
            array_map('intval', $ordered->countBy(fn (array $item): string => $item['source'])->all())
        );

        return [
            'range' => [
                'from' => $range['from']->toIso8601String(),
                'to' => $range['to']->toIso8601String(),
                'timezone' => $range['timezone'],
            ],
            'items' => $ordered->take($cap)->all(),
            'counts' => $counts,
            'truncated' => $ordered->count() > $cap,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string}
     */
    private function range(array $filters, User $scope): array
    {
        $timezone = ($filters['timezone'] ?? '') !== '' ? (string) $filters['timezone'] : $scope->declaredTimezone();

        if (! CalendarTime::isValidTimezone($timezone)) {
            abort(422, 'The timezone query must be a valid IANA identifier.');
        }

        $from = ($filters['from'] ?? '') !== '' ? CalendarTime::toUtc((string) $filters['from'], $timezone) : null;
        $to = ($filters['to'] ?? '') !== '' ? CalendarTime::toUtc((string) $filters['to'], $timezone) : null;

        if ($from !== null && $to !== null) {
            if ($to->lte($from)) {
                abort(422, 'The range end must be after its start.');
            }

            return ['from' => $from, 'to' => $to, 'timezone' => $timezone];
        }

        $resolved = CalendarTime::viewRange(
            (string) ($filters['view'] ?? 'week'),
            ($filters['date'] ?? '') !== '' ? (string) $filters['date'] : null,
            $timezone
        );

        return [
            'from' => $from ?? $resolved['from'],
            'to' => $to ?? $resolved['to'],
            'timezone' => $timezone,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function sources(array $filters): array
    {
        $raw = $filters['types'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return self::SOURCES;
        }

        $types = array_values(array_unique(array_map(
            static fn (mixed $t): string => trim((string) $t),
            is_array($raw) ? $raw : explode(',', (string) $raw)
        )));

        $unknown = array_values(array_diff($types, self::SOURCES));

        if ($unknown !== []) {
            abort(422, 'Unknown calendar source type: '.implode(', ', $unknown).'.');
        }

        return $types;
    }

    private function applyStatusAndPriority(Builder $query, array $filters): void
    {
        foreach (['status', 'priority'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $query->whereIn($key, array_map(
                    'trim',
                    explode(',', (string) $filters[$key])
                ));
            }
        }
    }

    private function applySearch(Builder $query, array $filters): void
    {
        if (($filters['q'] ?? '') !== '') {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']).'%');
        }
    }

    /**
     * كل التذكيرات المعلَّقة لكل صفوف المصدر في استعلام واحد، مفهرسة بمعرّف العنصر.
     *
     * القائمة لا الأدنى فقط: نموذج التحرير يعرض ما هو مجدول فعلًا، وإلا ظهر فارغًا ومسح ما لم
     * يلمسه المستخدم. حقل `reminder` المفرد يبقى كما كان — أبكر تذكير معلَّق هو صاحب أكبر إزاحة،
     * لأن لحظة التذكير = وقت الأساس ناقص الإزاحة — فلا تتغير شبكة تقرأه منذ Phase 5.
     *
     * @param  EloquentCollection<int, Model>  $models
     * @return array<int, list<int>>
     */
    private function reminderMap($models): array
    {
        if ($models->isEmpty()) {
            return [];
        }

        $type = $models->first()->getMorphClass();

        return Reminder::query()
            ->where('notifiable_type', $type)
            ->whereIn('notifiable_id', $models->modelKeys())
            ->where('status', ReminderStatus::Pending->value)
            ->orderBy('offset_minutes')
            ->get()
            ->groupBy('notifiable_id')
            ->map(function ($rows): array {
                $offsets = $rows->map(fn (Reminder $row): int => (int) $row->offset_minutes)->unique()->values()->all();
                sort($offsets);

                return $offsets;
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $source,
        int $id,
        string $kind,
        string $title,
        ?string $description,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        bool $allDay,
        string $timezone,
        string $status,
        string $priority,
        ?string $location,
        string $bucket,
        bool $overdue,
        ?int $participantCount,
        bool $editableByUser,
        array $reminderOffsets
    ): array {
        return [
            'source' => $source,
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'starts_at' => $start?->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'all_day' => $allDay,
            'timezone' => $timezone,
            'status' => $status,
            'overdue' => $overdue,
            'bucket' => $bucket,
            'priority' => $priority,
            'location' => $location,
            'participant_count' => $participantCount,
            'editable_by_user' => $editableByUser,
            'reminder' => $reminderOffsets === []
                ? null
                : ['offset_minutes' => max($reminderOffsets)],
            'reminder_offsets' => $reminderOffsets,
        ];
    }

    /**
     * طور عنصر محسوم: الجلسة الفائتة أو الملغاة لا تُسمّى «متأخرة» — المتأخر حكم على مهمة لم تُفعَل.
     *
     * @return string one of completed|cancelled|past|today|upcoming
     */
    private function phase(?CarbonImmutable $start, string $status, string $timezone, CarbonImmutable $now): string
    {
        if (in_array($status, [
            SessionStatus::Ended->value,
            PersonalTaskStatus::Completed->value,
            'completed',
        ], true)) {
            return 'completed';
        }

        if (in_array($status, [SessionStatus::Cancelled->value, PersonalTaskStatus::Cancelled->value], true)) {
            return 'cancelled';
        }

        if ($start === null || $status === SessionStatus::Missed->value) {
            return 'past';
        }

        $today = CalendarTime::startOfDay($now, $timezone);

        if ($start->lt($today)) {
            return 'past';
        }

        return $start->lt($today->addDay()) ? 'today' : 'upcoming';
    }

    private function cap(): int
    {
        return $this->cap ??= max(1, (int) AppSetting::getValue('calendar.range_max_items', self::MAX_ITEMS_DEFAULT));
    }
}
