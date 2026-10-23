<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LiveParticipant extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        "can_publish_audio" => "boolean",
        "is_chat_banned" => "boolean",
        "joined_at" => "datetime",
        "left_at" => "datetime",
    ];
    public function session() { return $this->belongsTo(LiveSession::class, "live_session_id"); }
    public function user() { return $this->belongsTo(User::class); }
}
