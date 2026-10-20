<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatMessage extends Model {
    protected $fillable = ["thread_id", "sender_id", "sender_role", "body", "read_at", "file_path", "file_name"];
    protected $casts = ["read_at" => "datetime"];
    public function thread() { return $this->belongsTo(ChatThread::class, "thread_id"); }
    public function sender() { return $this->belongsTo(User::class, "sender_id"); }
}
