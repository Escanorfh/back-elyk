<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\ChatMessage;
use App\Broadcasting\FcmChannel;

class NewChatMessageNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $chatMessage;
    public function __construct(ChatMessage $chatMessage) {
        $this->chatMessage = $chatMessage;
    }
    public function via(object $notifiable): array {
        return ["mail", FcmChannel::class]; // In-app handles via real-time channel
    }
    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->subject("New Chat Message")
            ->line("You have a new message: " . \Str::limit($this->chatMessage->body, 50))
            ->action("Reply", url("/chat/{$this->chatMessage->thread_id}"));
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "New Message", "body" => \Str::limit($this->chatMessage->body, 50)];
    }
}
