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
        return [new PrivateChannel("live." . $this->message->live_session_id . ".admin")];
    }
    public function broadcastAs(): string { return "chat.message"; }
}
