<?php
namespace App\Http\Controllers;
use App\Enums\SessionStatus;
use App\Models\LiveSession;
use App\Models\LiveParticipant;
use App\Services\Calendar\SessionScheduleService;
use App\Services\LiveKitService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Gate;

/**
 * جلسات اللايف: القائمة، بدء البثّ وإنهاؤها، الدفع، المشاركون ورمز الدخول.
 *
 * لا موضع يكتب حالة الجلسة هنا: كل انتقال يمر عبر SessionScheduleService — قانون واحد في
 * App\Enums\SessionStatus وبوّابة calendar.status_widening_enabled — حتى لا تتباعد
 * الأزرار عن بوّابة LiveKit عن التقويم.
 */
class LiveSessionController extends Controller
{
    protected LiveKitService $liveKit;
    public function __construct(LiveKitService $liveKit, protected SessionScheduleService $schedule) { $this->liveKit = $liveKit; }

    public function index(Request $request) {
        if ($request->user()->role === "admin") {
            $sessions = LiveSession::withCount(["participants", "invites"])
                ->with(["payments.patient:id,name", "invites.patient:id,name"])
                ->orderByDesc("id")
                ->get();
            // المجموعة المدعوة لكل جلسة (للعرض في لوحة الأدمن).
            foreach ($sessions as $s) {
                $s->setAttribute("invites_count", $s->invites_count ?? 0);
                $s->setAttribute("invited_patients", $s->invites->map(function ($inv) {
                    return ["id" => $inv->patient_id, "name" => $inv->patient?->name];
                })->values());
            }
        } else {
            $me = $request->user()->id;
            // قاعدة واحدة: ملكية المريض + الحالات المعروضة (App\Enums\SessionStatus::PATIENT_LIST).
            $sessions = LiveSession::query()->listedForPatient($me)
                ->withCount("invites")
                ->orderByDesc("id")
                ->get();
            // حالة دفع المريض الحالي لكل جلسة (none/pending/paid/...).
            $paid = \App\Models\LiveSessionPayment::where("patient_id", $request->user()->id)
                ->whereIn("live_session_id", $sessions->pluck("id"))
                ->get()
                ->keyBy("live_session_id");
            // حالة الموافقة الإدارية لكل جلسة (مفتاحها live_session_id).
            $invites = \App\Models\LiveSessionPatient::where("patient_id", $request->user()->id)
                ->whereIn("live_session_id", $sessions->pluck("id"))
                ->get()
                ->keyBy("live_session_id");
            foreach ($sessions as $s) {
                $p = $paid->get($s->id);
                $paymentStatus = $p ? $p->status : "none";
                $s->setAttribute("my_payment_status", $paymentStatus);

                $invite = $invites->get($s->id);
                // بلا دعوة: الجلسة المفتوحة تُعامل كمعتمدة فقط عندما لا تتطلب موافقة الإدارة.
                $approvalStatus = $invite
                    ? $invite->approval_status
                    : ($s->requires_admin_approval ? "pending_approval" : "approved");
                $s->setAttribute("admin_approval_status", $approvalStatus);

                // البوابة المزدوجة: (مجانية أو مدفوعة) + موافقة إدارية.
                $isAccessible = ((int) $s->fee_amount <= 0 || $paymentStatus === "paid")
                    && $approvalStatus === "approved";
                $s->setAttribute("is_accessible", $isAccessible);

                // هل الجلسة خاصة بمجموعة معينة (بدعوات) أم مفتوحة؟
                $s->setAttribute("invites_count", $s->invites_count ?? 0);

                $s->setAttribute("visibility_state", match (true) {
                    (int) $s->fee_amount > 0 && $paymentStatus !== "paid" => "awaiting_payment",
                    $approvalStatus !== "approved" => "awaiting_admin_approval",
                    default => "confirmed_and_ready",
                });
            }
        }
        return response()->json(["data" => $sessions]);
    }

    public function store(Request $request) {
        if ($request->user()->role !== "admin") abort(403);
        // الإنشاء يمرّ بالخدمة نفسها: تدقيق المدعوين، فحص التعارض، التذكيرات وإشعار الموعد.
        // scheduled_at يبقى اختياريًا كما كان، فالأدمن ينشئ الجلسة أحيانًا بلا ميعاد.
        $validated = $request->validate(["title" => "required|string|max:255"] + SessionScheduleService::rules(required: false));
        $session = $this->schedule->create($request->user(), $validated);
        return response()->json(["data" => $session]);
    }

    // تعديل محتوى الجلسة وموعدها بلا انتقال حالة: المسار نفسه الذي يحمي التعارض والتذكيرات.
    public function update(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        $validated = $request->validate(SessionScheduleService::rules(required: false));
        $session = $this->schedule->update($session, $validated);
        return response()->json(["data" => $session]);
    }

    // الانتقالات الوسيطة (confirm/cancel/missed) تمرّ ببوّابة calendar.status_widening_enabled:
    // وهي مغلقة افتراضيًا فلا تكتب حالة لم يعرفها التطبيق قبل التقويم، وتُفتح عندما يكون جاهزًا.
    public function confirm(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        return response()->json(["data" => $this->schedule->confirm($session)]);
    }

    public function cancel(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        $validated = $request->validate(["reason" => "nullable|string|max:1000"]);
        $session = $this->schedule->cancel($session, $validated["reason"] ?? null);
        return response()->json(["data" => $session]);
    }

    // إعادة الجدولة تاريخٌ لا حالة: العمودان previous_scheduled_at/rescheduled_at يحفظان ما كان.
    public function reschedule(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        $validated = $request->validate([
            "scheduled_at" => "required|date",
            "ends_at" => "nullable|date",
            "reminder_offsets" => "nullable|array",
            "reminder_offsets.*" => ["integer", "min:0", "max:20160", "distinct"],
        ]);
        $session = $this->schedule->reschedule(
            $session,
            $validated["scheduled_at"],
            $validated["ends_at"] ?? null,
            $request->user(),
            $validated["reminder_offsets"] ?? null
        );
        return response()->json(["data" => $session]);
    }

    public function markMissed(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        return response()->json(["data" => $this->schedule->markMissed($session)]);
    }

    public function start(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        // scheduled|confirmed وحدهما تُشعلان لاييفًا؛ المباشرة لا تُشعل مرتين.
        $session = $this->schedule->markLive($session);
        // الأدمن حر في فتح اللايف في أي وقت — لا نتحقق من اليوم/الساعة المختارة.
        // LiveKit اختياري: إن لم يكن السيرفر يعمل نكمل بدونه (الفيديو placeholder).
        try {
            $this->liveKit->createRoom($session->livekit_room_name);
        } catch (\Exception $e) {
            \Log::warning("LiveKit createRoom failed, continuing without video room: " . $e->getMessage());
        }

        // إشعار المرضى ببدء البث (صفحة الإشعارات + دفع للجهاز).
        // ملفوف بـ try/catch حتى لا يفشل بدء الجلسة بسبب الإشعارات.
        try {
            foreach ($this->liveAudience($session) as $patient) {
                try {
                    $patient->notify(new \App\Notifications\LiveStartedNotification($session));
                } catch (\Throwable $e) {
                    \Log::warning("LiveStartedNotification failed: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            \Log::warning("liveAudience failed on start: " . $e->getMessage());
        }

        return response()->json(["message" => "Session started", "data" => $session]);
    }

    public function end(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        // التحويل أولًا: جلسة نهائية لا تُهدم غرفتها ثم تُرفض. الخدمة تُطفئ تذكيراتها معها.
        $session = $this->schedule->markEnded($session);
        try {
            $this->liveKit->deleteRoom($session->livekit_room_name);
        } catch (\Exception $e) {
            \Log::warning("LiveKit deleteRoom failed: " . $e->getMessage());
        }
        return response()->json(["message" => "Session ended", "data" => $session]);
    }

    // المريض يسجّل دفعه (يدوي — برقم التحويل) بعد تحديد اللايف فقط.
    public function pay(Request $request, LiveSession $session) {
        $user = $request->user();
        if ($user->role !== "patient") abort(403);
        // خيار الدفع يُفتح فقط بعد تحديد اللايف (مجدولة/مؤكَّدة/مباشرة).
        if (!in_array((string) $session->status, SessionStatus::PAYABLE, true)) {
            abort(400, "Payment is available only after the live is scheduled.");
        }
        if (!$session->fee_amount) abort(400, "This session has no fee.");
        if (!$this->canAccess($session, $user)) abort(403);

        $validated = $request->validate([
            "reference" => "nullable|string|max:255",
        ]);

        $payment = \App\Models\LiveSessionPayment::firstOrNew([
            "live_session_id" => $session->id,
            "patient_id" => $user->id,
        ]);
        // دفع مؤكد سابقاً لا يُرجَع لمعلق.
        if ($payment->exists && $payment->status === "paid") {
            return response()->json(["message" => "Already paid", "data" => $payment]);
        }
        $payment->fill([
            "amount" => $session->fee_amount,
            "currency" => $session->fee_currency ?? "EGP",
            "status" => "pending",
            "provider_reference" => $validated["reference"] ?? null,
        ])->save();

        return response()->json(["message" => "Payment recorded, waiting for confirmation", "data" => $payment]);
    }

    // الأدمن يؤكد استلام الدفع (زرار Accept واحد).
    // الفكرة: المريض دفع عبر لينك انستاباي الدكتورة → الدكتورة تتأكد من التحويل
    // → تدوس Accept هنا → الدفع يبقى paid + الدعوة تبقى approved معاً،
    // فيقدر يدخل اللايف أول ما يفتح (Gate 1 + Gate 2 معاً).
    public function confirmPayment(Request $request, LiveSession $session, \App\Models\LiveSessionPayment $payment) {
        if ($request->user()->role !== "admin") abort(403);
        if ($payment->live_session_id !== $session->id) abort(404);
        $payment->update(["status" => "paid", "paid_at" => now()]);

        // قبول تلقائي للدعوة مع تأكيد الدفع — دخول واحد بدل خطوتين.
        $invite = \App\Models\LiveSessionPatient::firstOrCreate(
            ["live_session_id" => $session->id, "patient_id" => $payment->patient_id],
            ["approval_status" => "pending_approval"]
        );
        if ($invite->approval_status !== "approved") {
            $invite->fill([
                "approval_status" => "approved",
                "approved_by" => $request->user()->id,
                "approved_at" => now(),
                "rejection_reason" => null,
            ])->save();

            try {
                $patient = \App\Models\User::find($payment->patient_id);
                if ($patient) $patient->notify(new \App\Notifications\SessionApprovedNotification($session));
            } catch (\Throwable $e) {
                \Log::warning("SessionApprovedNotification failed after payment confirm: " . $e->getMessage());
            }
        }

        if ($payment->patient) {
            try {
                $payment->patient->notify(new \App\Notifications\LivePaymentConfirmedNotification($session));
            } catch (\Throwable $e) {
                \Log::warning("LivePaymentConfirmedNotification failed: " . $e->getMessage());
            }
        }

        return response()->json(["message" => "Payment confirmed and session approved", "data" => $payment->fresh()]);
    }

    // جمهور البث: المدعوون إن وُجدوا، وإلا المريض المرتبط أو أصحاب المسارات النشطة.
    protected function liveAudience(LiveSession $session) {
        $invited = $session->invitedPatients()->get();
        if ($invited->isNotEmpty()) return $invited;
        if ($session->patient_id) {
            $patient = \App\Models\User::find($session->patient_id);
            return $patient ? collect([$patient]) : collect();
        }
        $ids = \App\Models\PatientPath::where("status", "active")->distinct()->pluck("patient_id");
        return \App\Models\User::whereIn("id", $ids)->get();
    }

    // هل يحق للمستخدم رؤية/دخول الجلسة؟ (مدعو — أو عامة بلا دعوات)
    protected function canAccess(LiveSession $session, \App\Models\User $user): bool {
        if ($user->role === "admin") return $user->id === $session->admin_id;
        if ($session->invites()->exists()) {
            return $session->invites()->where("patient_id", $user->id)->exists();
        }
        return $session->patient_id === null || $session->patient_id === $user->id;
    }

    // قائمة المشاركين في الغرفة (للأدمن والإدارة داخل الغرفة).
    public function participants(Request $request, LiveSession $session) {
        $user = $request->user();
        $isOwner = $user->id === $session->admin_id;
        $isJoined = \App\Models\LiveParticipant::where("live_session_id", $session->id)
            ->where("user_id", $user->id)->exists();
        if (!$isOwner && !$isJoined) abort(403);

        $parts = \App\Models\LiveParticipant::with("user:id,name")
            ->where("live_session_id", $session->id)
            ->whereNull("left_at")
            ->orderBy("joined_at")
            ->get();
        $hands = \App\Models\LiveRaiseHandRequest::where("live_session_id", $session->id)
            ->where("status", "pending")->pluck("participant_id")->all();
        foreach ($parts as $p) {
            $p->setAttribute("hand_raised",
                $p->status === "raised_hand" || in_array($p->id, $hands));
        }
        return response()->json(["data" => $parts]);
    }

    // فتح/قفل مايك مشارك (الأدمن يتحكم بمايك أي حد).
    public function setAudio(Request $request, LiveSession $session, \App\Models\LiveParticipant $participant) {
        Gate::authorize("update", $session);
        if ($participant->live_session_id !== $session->id) abort(404);
        $validated = $request->validate(["allowed" => "required|boolean"]);
        $allowed = (bool) $validated["allowed"];
        $participant->update([
            "can_publish_audio" => $allowed,
            "status" => $allowed ? "speaking" : "connected",
        ]);
        try {
            $this->liveKit->updateParticipantPermissions(
                $session->livekit_room_name, $participant->livekit_identity, $allowed);
        } catch (\Exception $e) {
            \Log::warning("LiveKit permission update failed: " . $e->getMessage());
        }
        return response()->json([
            "message" => $allowed ? "Microphone opened" : "Microphone muted",
            "data" => $participant->fresh(),
        ]);
    }

    // حظر/فك حظر مريض من الشات (ban).
    public function banChat(Request $request, LiveSession $session, \App\Models\LiveParticipant $participant) {
        Gate::authorize("update", $session);
        if ($participant->live_session_id !== $session->id) abort(404);
        $validated = $request->validate(["banned" => "required|boolean"]);
        $participant->update(["is_chat_banned" => (bool) $validated["banned"]]);
        return response()->json([
            "message" => $validated["banned"] ? "Chat banned" : "Chat unbanned",
            "data" => $participant->fresh(),
        ]);
    }

    public function destroy(Request $request, LiveSession $session) {
        Gate::authorize("update", $session);
        if ($session->status === SessionStatus::Live->value) {
            try {
                $this->liveKit->deleteRoom($session->livekit_room_name);
            } catch (\Exception $e) {}
        }
        $session->delete();
        return response()->json(["message" => "Session deleted"]);
    }

    public function joinToken(Request $request, LiveSession $session) {
        if ($session->status !== SessionStatus::Live->value && $request->user()->role !== "admin") {
            abort(400, "Session is not live.");
        }

        // الدخول للمختارين فقط عندما توجد دعوات.
        if ($request->user()->role !== "admin" && !$this->canAccess($session, $request->user())) {
            abort(403, "You are not invited to this live session.");
        }
        // بوابة الدفع (Gate 1): الجلسة ذات الرسوم تتطلب دفعاً مؤكداً (الأدمن مستثنى).
        if ($request->user()->role !== "admin" && (int) $session->fee_amount > 0) {
            $paid = \App\Models\LiveSessionPayment::where("live_session_id", $session->id)
                ->where("patient_id", $request->user()->id)
                ->where("status", "paid")
                ->exists();
            if (!$paid) abort(402, "Payment required to join this live session.");
        }

        // بوابة الموافقة الإدارية (Gate 2): تتطلب دعوة معتمدة عندما تفرضها الجلسة
        // (requires_admin_approval = true افتراضياً — يطابق مقتطف المواصفة).
        // الجلسات المفتوحة (is_public / بدون موافقة) تتجاوز هذه البوابة.
        if ($request->user()->role !== "admin" && $session->requires_admin_approval) {
            $invite = \App\Models\LiveSessionPatient::where("live_session_id", $session->id)
                ->where("patient_id", $request->user()->id)
                ->first();
            if (!$invite || $invite->approval_status !== "approved") {
                abort(403, "Session is pending administrative approval.");
            }
        }

        // Gating logic will be implemented here later (Eligibility & Payment)

        $participant = LiveParticipant::firstOrCreate(
            ["live_session_id" => $session->id, "user_id" => $request->user()->id],
            [
                "role" => $request->user()->role,
                "anonymous_label" => "Participant " . (LiveParticipant::where("live_session_id", $session->id)->count() + 1),
                "livekit_identity" => "participant_" . Str::random(12),
                "status" => "connected",
                "can_publish_audio" => $request->user()->role === "admin",
                "joined_at" => now(),
            ]
        );
        $participant->update(["status" => "connected", "left_at" => null]);

        // السماح بالكاميرا والمايك؛ المريض يبدأ مستمعاً ويُفتح له المايك بعد قبول الأدمن لطلب رفع اليد.
        $canPublishAudio = $participant->can_publish_audio;
        $canPublishVideo = $request->user()->role === "admin" || $participant->can_publish_audio;

        $token = $this->liveKit->createAccessToken(
            $session->livekit_room_name,
            $participant->livekit_identity,
            $participant->anonymous_label,
            $canPublishAudio,
            $canPublishVideo
        );

        return response()->json(["data" => [
            "token" => $token,
            "livekit_url" => config("services.livekit.ws_url", "ws://127.0.0.1:7880"),
            "participant" => $request->user()->role === "admin" 
                ? new \App\Http\Resources\AdminParticipantResource($participant) 
                : new \App\Http\Resources\PatientParticipantResource($participant)
        ]]);
    }
}
