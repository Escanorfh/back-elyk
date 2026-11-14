<?php
namespace App\Events;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\LiveChatMessage;

class LiveChatMessageEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $message;
    public function __construct(LiveChatMessage $message) {
        $this->message = $message->load("participant.user");
    }
    public function broadcastOn(): array {
        return [
            // قناة الأدمن (توافق مع أي مستهلك قائم) + قناة الجلسة المشتركة
            // ليصل الشات لكل المشاركين حيًا بدل polling.
            new PrivateChannel("live." . $this->message->live_session_id . ".admin"),
            new PrivateChannel("live." . $this->message->live_session_id),
        ];
    }
    public function broadcastAs(): string { return "chat.message"; }

    // حمولة مقصودة: بدون كائن user كامل — قناة الجلسة تصل لكل المشاركين،
    // والهوية في الجلسة تُعرَف بـ anonymous_label وليس بحساب المستخدم.
    public function broadcastWith(): array {
        $participant = $this->message->participant;
        return [
            "id" => $this->message->id,
            "live_session_id" => $this->message->live_session_id,
            "participant_id" => $this->message->participant_id,
            "body" => $this->message->body,
            "is_pinned" => (bool) $this->message->is_pinned,
            "sender_label" => $participant?->anonymous_label,
            "sender_role" => $participant?->role,
            "created_at" => optional($this->message->created_at)->toISOString(),
        ];
    }
}
