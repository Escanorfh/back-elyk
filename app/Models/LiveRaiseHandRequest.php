<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LiveRaiseHandRequest extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        "requested_at" => "datetime",
        "resolved_at" => "datetime",
    ];
    public function session() { return $this->belongsTo(LiveSession::class, "live_session_id"); }
    public function participant() { return $this->belongsTo(LiveParticipant::class); }
    public function resolver() { return $this->belongsTo(User::class, "resolved_by"); }
}
