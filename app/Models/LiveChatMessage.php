<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LiveChatMessage extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        "is_pinned" => "boolean",
    ];
    public function session() { return $this->belongsTo(LiveSession::class, "live_session_id"); }
    public function participant() { return $this->belongsTo(LiveParticipant::class); }
}
