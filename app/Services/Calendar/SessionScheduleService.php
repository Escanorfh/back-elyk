<?php

namespace App\Services\Calendar;

use App\Enums\Priority;
use App\Enums\ReminderAudience;
use App\Enums\ReminderStatus;
use App\Enums\SessionStatus;
use App\Models\AppSetting;
use App\Models\LiveSession;
use App\Models\LiveSessionPatient;
use App\Models\User;
use App\Notifications\LiveCancelledNotification;
use App\Notifications\LiveRescheduledNotification;
use App\Notifications\LiveScheduledNotification;
use App\Support\CalendarTime;
use App\Support\SessionStatusGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * دورة حياة جلسة اللايف: الجدولة، التعديل، التأكيد، الإلغاء، إعادة الجدولة، والفوت.
 *
 * كل كتابة للحالة تمر عبر App\Support\SessionStatusGate: القواعد في App\Enums\SessionStatus،
 * وبوّابة calendar.status_widening_enabled تحجب confirmed|cancelled|missed حتى يُعتمَد فتحها.
 * الحالات القديمة الثلاث (scheduled|live|ended) تُكتب دائمًا فلا تنكسر Behavior القائمة.
 *
 * الأوقات مخزَّنة UTC؛ عمود timezone يحمل منطقة العرض المعلنة لا إزاحة مطبَّقة.
 */
class SessionScheduleService
{
    public function __construct(
        private readonly ReminderSyncService $reminders,
        private readonly ConflictDetectionService $conflicts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function previewConflicts(User $admin, array $data): array
    {
        $timezone = $this->timezone($data, null);
        [$start, $end] = $this->window($data['scheduled_at'], $data['ends_at'] ?? null, $timezone);
        $ids = $this->inviteIds($data, (int) ($data['patient_id'] ?? 0));
        $exclude = isset($data['exclude_session_id']) ? (int) $data['exclude_session_id'] : null;

        $report = $this->report($admin->id, $ids, $start, $end, $exclude);

        return $report + [
            'scheduled_at' => $start->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'timezone' => $timezone,
            'patient_ids' => $ids,
        ];
    }

    /** @return array<string, mixed> */
    public static function conflictRules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
            'patient_id' => 'nullable|integer|exists:users,id',
            'patient_ids' => 'nullable|array',
            'patient_ids.*' => 'integer|exists:users,id',
            'exclude_session_id' => 'nullable|integer|exists:live_sessions,id',
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(bool $required = true): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'scheduled_at' => [$required ? 'required' : 'sometimes', 'date'],
            'ends_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'fee_amount' => 'nullable|integer|min:0',
            'fee_currency' => 'nullable|string|size:3',
            'patient_id' => 'nullable|integer|exists:users,id',
            'patient_ids' => 'nullable|array',
            'patient_ids.*' => 'integer|exists:users,id',
            'allow_conflict' => 'nullable|boolean',
            'reminder_offsets' => 'nullable|array',
            'reminder_offsets.*' => ['integer', 'min:0', 'max:20160', 'distinct'],
        ];
    }

    /**
     * إنشاء جلسة. scheduled_at اختياري كما كان في المسار القديم: بلا لحظة لا نافذة ولا
     * تذكير ولا إشعار موعد — الجلسة تُبلى «بلا تاريخ» وتُجدول لاحقًا.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $admin, array $data): LiveSession
    {
        $timezone = $this->timezone($data, null);
        $hasInstant = ($data['scheduled_at'] ?? '') !== '';
        [$start, $end] = $hasInstant
            ? $this->window($data['scheduled_at'], $data['ends_at'] ?? null, $timezone)
            : [null, null];

        $inviteIds = $this->inviteIds($data, (int) ($data['patient_id'] ?? 0));

        if ($start !== null) {
            $this->assertNoConflict($inviteIds, $admin->id, $start, $end, null, (bool) ($data['allow_conflict'] ?? false));
        }

        $session = LiveSession::create([
            'admin_id' => $admin->id,
            'patient_id' => $data['patient_id'] ?? null,
            'title' => $data['title'],
            'status' => SessionStatus::Scheduled->value,
            'scheduled_at' => $start,
            'ends_at' => $end,
            'timezone' => $timezone,
            'priority' => $data['priority'] ?? Priority::Normal->value,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'fee_amount' => $data['fee_amount'] ?? AppSetting::getValue('default_session_price'),
            'fee_currency' => $data['fee_currency'] ?? AppSetting::getValue('session_currency', 'EGP'),
            'livekit_room_name' => 'room_'.Str::random(10),
        ]);

        foreach ($inviteIds as $pid) {
            LiveSessionPatient::firstOrCreate([
                'live_session_id' => $session->id,
                'patient_id' => $pid,
            ]);
        }

        $this->syncReminders($session, $this->offsets($data) ?? [], $timezone);
        $this->notifyAudience($session->fresh(), new LiveScheduledNotification($session));

        return $session->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(LiveSession $session, array $data): LiveSession
    {
        if (SessionStatusGate::isTerminal($session->status)) {
            abort(409, "A session in state '{$session->status}' cannot be edited.");
        }

        $timezone = $this->timezone($data, $session);
        $payload = [];

        foreach (['title', 'description', 'location', 'priority'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        $moved = array_key_exists('scheduled_at', $data) || array_key_exists('ends_at', $data);

        if ($moved) {
            // الطرف الذي لم يُلمَس يعود بإزاحته الصريحة حتى لا تتزاح اللحظة عند تغيير نصف النافذة.
            [$start, $end] = $this->window(
                $data['scheduled_at'] ?? $session->scheduled_at?->toIso8601String(),
                $data['ends_at'] ?? $session->ends_at?->toIso8601String(),
                $timezone
            );

            $this->assertNoConflict(
                $this->roster($session), $session->admin_id, $start, $end, $session->id,
                (bool) ($data['allow_conflict'] ?? false)
            );

            $payload['scheduled_at'] = $start;
            $payload['ends_at'] = $end;
        }

        if ($timezone !== CalendarTime::timezoneOf($session->timezone)) {
            $payload['timezone'] = $timezone;
        }

        if ($payload !== []) {
            $session->update($payload);
        }

        $offsets = $this->offsets($data);

        if ($offsets !== null || $moved) {
            $this->syncReminders($session->fresh(), $offsets ?? $this->pendingOffsets($session), $timezone);
        }

        return $session->fresh();
    }

    public function confirm(LiveSession $session): LiveSession
    {
        SessionStatusGate::assertTransition($session->status, SessionStatus::Confirmed->value);

        $session->update(['status' => SessionStatus::Confirmed->value, 'confirmed_at' => now()]);

        return $session->fresh();
    }

    public function cancel(LiveSession $session, ?string $reason): LiveSession
    {
        SessionStatusGate::assertTransition($session->status, SessionStatus::Cancelled->value);

        $session->update([
            'status' => SessionStatus::Cancelled->value,
            'cancelled_at' => now(),
            'description' => $this->appendReason($session->description, $reason),
        ]);

        // جلسة ملغاة لا تُذكِّر: كل ما لم يُرسَل يُلغى معها.
        $this->reminders->cancelAll($session);
        $this->notifyAudience($session->fresh(), new LiveCancelledNotification($session, $reason));

        return $session->fresh();
    }

    /**
     * إعادة الجدولة تاريخٌ لا حالة: previous_scheduled_at + rescheduled_at + rescheduled_by،
     * والحالة تعود scheduled. التذكيرات المعلَّقة تُنقل مع الموعد الجديد بدل أن تتيتم.
     *
     * @param  list<int>|null  $offsets  null = إبقاء الإزاحات القائمة
     */
    public function reschedule(LiveSession $session, mixed $newStart, ?string $newEnd, User $actor, ?array $offsets = null): LiveSession
    {
        $timezone = CalendarTime::timezoneOf($session->timezone);

        try {
            [$start, $end] = CalendarTime::window($newStart, $newEnd, $timezone);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // بثّ جارٍ لا يُسحب إلى الخلف: live → scheduled مرفوض في القاعدة.
        SessionStatusGate::assertTransition($session->status, SessionStatus::Scheduled->value);

        $this->assertNoConflict($this->roster($session), $session->admin_id, $start, $end, $session->id, false);

        $carried = $offsets ?? $this->pendingOffsets($session);

        $session->update([
            'status' => SessionStatus::Scheduled->value,
            'scheduled_at' => $start,
            'ends_at' => $end,
            'previous_scheduled_at' => $session->scheduled_at,
            'rescheduled_at' => now(),
            'rescheduled_by' => $actor->id,
        ]);

        $fresh = $session->fresh();

        $this->syncReminders($fresh, $carried, $timezone);
        $this->notifyAudience($fresh, new LiveRescheduledNotification($fresh));

        return $fresh;
    }

    public function markMissed(LiveSession $session): LiveSession
    {
        SessionStatusGate::assertTransition($session->status, SessionStatus::Missed->value);

        $session->update(['status' => SessionStatus::Missed->value, 'missed_at' => now()]);
        $this->reminders->cancelAll($session);

        return $session->fresh();
    }

    /**
     * بدء البث: المسار القديم كان يشترط scheduled وحدها؛ مع التأكيد الوسيط يجب قبول
     * scheduled|confirmed وإلا تعذّر بدء جلسة مؤكَّدة بعد فتح البوابة.
     */
    public function markLive(LiveSession $session): LiveSession
    {
        if (! in_array((string) $session->status, SessionStatus::BOOKED, true)) {
            abort(409, 'Only a scheduled or confirmed session can go live.');
        }

        SessionStatusGate::assertTransition($session->status, SessionStatus::Live->value);

        $session->update(['status' => SessionStatus::Live->value, 'started_at' => now()]);

        return $session->fresh();
    }

    /**
     * إنهاء البث. ملغاة أو منتهية أو فائتة حالة نهائية: بوّابة LiveKit تأتي من الخارج ولا يجوز
     * أن تُعيد جلسة ألغاها الأدمن إلى ended.
     */
    public function markEnded(LiveSession $session): LiveSession
    {
        if (! in_array((string) $session->status, SessionStatus::CLOSABLE, true)) {
            abort(409, "A session in state '{$session->status}' cannot be moved to ended.");
        }

        SessionStatusGate::assertTransition($session->status, SessionStatus::Ended->value);

        $session->update(['status' => SessionStatus::Ended->value, 'ended_at' => now()]);
        $this->reminders->cancelAll($session);

        return $session->fresh();
    }

    /** @return list<int> */
    private function pendingOffsets(LiveSession $session): array
    {
        return array_values(array_map(
            'intval',
            $session->reminders()->where('status', ReminderStatus::Pending->value)->pluck('offset_minutes')->all()
        ));
    }

    /**
     * @param  list<int>  $offsets
     */
    private function syncReminders(LiveSession $session, array $offsets, string $timezone): void
    {
        // جلسة بلا ميعاد لا تُذكّر أحدًا: لا لحظة يُحسب عليها الإزاحة.
        if ($session->scheduled_at === null) {
            return;
        }

        $this->reminders->sync(
            $session, $offsets, $session->scheduled_at->setTimezone('UTC'), $timezone,
            ReminderAudience::Participants->value
        );
    }

    /** @return list<int> */
    private function roster(LiveSession $session): array
    {
        $ids = array_values(array_map('intval', LiveSessionPatient::where('live_session_id', $session->id)->pluck('patient_id')->all()));

        if ($ids !== []) {
            return $ids;
        }

        return $session->patient_id ? [(int) $session->patient_id] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function inviteIds(array $data, int $fallbackPatientId): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($data['patient_ids'] ?? []))));

        if ($ids === [] && $fallbackPatientId > 0) {
            $ids = [$fallbackPatientId];
        }

        foreach ($ids as $pid) {
            $patient = User::find($pid);

            if (! $patient || $patient->role !== 'patient') {
                abort(422, 'Invited users must be patients.');
            }
        }

        return $ids;
    }

    /**
     * تعارض المريض يعني مريضين في لاييف واحد في نفس الوقت؛ تعارض الأديبة يعني غرفتين لها.
     * جلسة بلا جمهور (مفتوحة) لا تُثقل جدول أحد، لكن تبقى مقيدة بجدول الأدمن.
     *
     * @param  list<int>  $patientIds
     */
    private function assertNoConflict(array $patientIds, int $adminId, CarbonImmutable $start, ?CarbonImmutable $end, ?int $excludeId, bool $allow): void
    {
        if ($allow) {
            return;
        }

        $report = $this->report($adminId, $patientIds, $start, $end, $excludeId);

        if (! $report['has_conflict']) {
            return;
        }

        abort(response()->json([
            'message' => 'The requested time conflicts with an existing booking.',
            'conflicts' => $report['conflicts'],
        ], 409));
    }

    /**
     * التقرير واحد للفحصين: الرفض عند الحفظ والفحص المسبق من endpoint الإدارة — بصيغة واحدة
     * فلا ترى لوحة الأدمن تعارضًا مختلفًا عن الذي سيرفضه الحفظ.
     *
     * @param  list<int>  $patientIds
     * @return array{has_conflict: bool, conflicts: array{patients: list<array<string, mixed>>, therapist: list<array<string, mixed>>}}
     */
    private function report(int $adminId, array $patientIds, CarbonImmutable $start, ?CarbonImmutable $end, ?int $excludeId): array
    {
        $patientClash = $patientIds === []
            ? collect()
            : $this->conflicts->sessionConflictsForPatients($patientIds, $start, $end, $excludeId);

        $therapistClash = $this->conflicts->therapistConflicts($adminId, $start, $end, $excludeId);

        return [
            'has_conflict' => ! ($patientClash->isEmpty() && $therapistClash->isEmpty()),
            'conflicts' => [
                'patients' => $this->clashRows($patientClash),
                'therapist' => $this->clashRows($therapistClash),
            ],
        ];
    }

    /** @param  Collection<int, LiveSession>  $clash  @return list<array<string, mixed>> */
    private function clashRows(Collection $clash): array
    {
        return $clash->map(fn (LiveSession $s): array => [
            'id' => $s->id,
            'title' => $s->title,
            'status' => $s->status,
            'scheduled_at' => $s->scheduled_at?->setTimezone('UTC')->toIso8601String(),
            'ends_at' => $s->ends_at?->setTimezone('UTC')->toIso8601String(),
        ])->values()->all();
    }

    /** @param  array<string, mixed>  $data */
    private function timezone(array $data, ?LiveSession $session): string
    {
        if (isset($data['timezone'])) {
            return CalendarTime::timezoneOf($data['timezone']);
        }

        return CalendarTime::timezoneOf($session?->timezone);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    private function window(mixed $start, mixed $end, string $timezone): array
    {
        try {
            return CalendarTime::window($start, $end, $timezone);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function appendReason(?string $description, ?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return $description;
        }

        $note = 'Cancelled: '.trim($reason);

        return $description ? $description."\n".$note : $note;
    }

    /** جمهور الجلسة: المدعوون إن وُجدوا، وإلا المريض المرتبط بها (المفتوحة بلا جمهور مُعلن لا تُشعر أحدًا). */
    private function notifyAudience(LiveSession $session, object $notification): void
    {
        $audience = $this->audience($session);

        foreach ($audience as $patient) {
            try {
                $patient->notify($notification);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @return Collection<int, User> */
    private function audience(LiveSession $session): Collection
    {
        $invited = $session->invitedPatients()->get();

        if ($invited->isNotEmpty()) {
            return $invited;
        }

        if ($session->patient_id) {
            $patient = User::find($session->patient_id);

            return $patient ? collect([$patient]) : collect();
        }

        return collect();
    }

    /** @return list<int>|null */
    private function offsets(array $data): ?array
    {
        if (! array_key_exists('reminder_offsets', $data)) {
            return null;
        }

        return array_values(array_map('intval', (array) $data['reminder_offsets']));
    }
}
