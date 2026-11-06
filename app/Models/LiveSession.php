<?php
namespace App\Models;
use App\Enums\SessionStatus;
use App\Support\CalendarTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LiveSession extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        "started_at" => "datetime",
        "ended_at" => "datetime",
        "scheduled_at" => "datetime",
        "is_public" => "boolean",
        "requires_admin_approval" => "boolean",
        "ends_at" => "datetime",
        "confirmed_at" => "datetime",
        "cancelled_at" => "datetime",
        "missed_at" => "datetime",
        "previous_scheduled_at" => "datetime",
        "rescheduled_at" => "datetime",
    ];
    public function admin() { return $this->belongsTo(User::class, "admin_id"); }
    public function rescheduledBy() { return $this->belongsTo(User::class, "rescheduled_by"); }
    public function reminders() { return $this->morphMany(Reminder::class, "notifiable"); }
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function participants() { return $this->hasMany(LiveParticipant::class); }
    public function payments() { return $this->hasMany(LiveSessionPayment::class); }
    public function invites() { return $this->hasMany(LiveSessionPatient::class); }
    public function invitedPatients() {
        return $this->belongsToMany(User::class, "live_session_patients", "live_session_id", "patient_id");
    }
    public function pinnedMessage() {
        return $this->hasOne(LiveChatMessage::class)->where("is_pinned", true)->latestOfMany();
    }

    // ملكية المريض للجلسة: مدعوّ صريح، أو جلسة بلا دعوات ومفتوحة (patient_id فارغ أو هو).
    // قاعدة واحدة يستخدمها قائمة /live-sessions وتقويم المريض معًا — لا نسخ للمنطق.
    public function scopeVisibleToPatient(Builder $query, int $patientId): Builder {
        return $query->where(function ($q) use ($patientId) {
            $q->whereHas("invites", function ($qq) use ($patientId) {
                $qq->where("patient_id", $patientId);
            })->orWhere(function ($q2) use ($patientId) {
                $q2->whereDoesntHave("invites")
                    ->where(function ($q3) use ($patientId) {
                        $q3->whereNull("patient_id")->orWhere("patient_id", $patientId);
                    });
            });
        });
    }

    // قائمة المريض: الملكية + الحالات المعروضة، في موضع واحد حتى لا تتباعد القائمة عن التقويم.
    public function scopeListedForPatient(Builder $query, int $patientId): Builder {
        return $query->visibleToPatient($patientId)->whereIn("status", SessionStatus::PATIENT_LIST);
    }

    /** نافذة الجلسة [scheduled_at, ends_at)؛ بلا تاريخ مخطط لا تدخل أي نافذة. */
    public function scopeScheduledInRange(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder {
        return CalendarTime::whereOverlaps($query, "scheduled_at", "ends_at", $from, $to);
    }
}
