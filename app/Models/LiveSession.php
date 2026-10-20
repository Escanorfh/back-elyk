<?php
namespace App\Models;
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
    ];
    public function admin() { return $this->belongsTo(User::class, "admin_id"); }
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
}
