<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// دعوة مريض لجلسة لايف (يختارهم الأدمن — بنفس المرض).
class LiveSessionPatient extends Model {
    protected $guarded = [];
    protected $casts = [
        "approved_at" => "datetime",
    ];
    public function session() { return $this->belongsTo(LiveSession::class, "live_session_id"); }
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function approver() { return $this->belongsTo(User::class, "approved_by"); }
}
