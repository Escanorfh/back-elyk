<?php
namespace App\Events;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow {
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $message;
    public function __construct(ChatMessage $message) {
        $this->message = $message;
    }
    public function broadcastOn(): array {
        return [
            new PrivateChannel("chat.thread." . $this->message->thread_id)
        ];
    }
    public function broadcastWith(): array {
        return ["message" => $this->message->load("sender")];
    }
}
