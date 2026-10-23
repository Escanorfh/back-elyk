<?php
$base = __DIR__;

function writeClass($file, $content) {
    global $base;
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeClass('app/Events/AttemptFinalized.php', '<?php
namespace App\Events;
use App\Models\AttemptResult;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class AttemptFinalized {
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
}
');

writeClass('app/Listeners/NotifySevereResult.php', '<?php
namespace App\Listeners;
use App\Events\AttemptFinalized;
use App\Models\User;
use App\Notifications\SevereExamResultNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifySevereResult implements ShouldQueue {
    public function handle(AttemptFinalized $event): void {
        $result = $event->result;
        
        $isSevere = false;
        if ($result->severityBand && $result->severityBand->is_alert_worthy) {
            $isSevere = true;
        } else {
            foreach ($result->attempt->subscaleResults as $subRes) {
                if ($subRes->severityBand && $subRes->severityBand->is_alert_worthy) {
                    $isSevere = true;
                    break;
                }
            }
        }

        if ($isSevere) {
            $admins = User::where("role", "admin")->get();
            \Illuminate\Support\Facades\Notification::send($admins, new SevereExamResultNotification($result));
        }
    }
}
');

writeClass('app/Listeners/NotifyPatientResultReady.php', '<?php
namespace App\Listeners;
use App\Events\AttemptFinalized;
use App\Notifications\ExamResultReadyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPatientResultReady implements ShouldQueue {
    public function handle(AttemptFinalized $event): void {
        $result = $event->result;
        $patient = $result->attempt->patient;
        if ($patient) {
            $patient->notify(new ExamResultReadyNotification($result));
        }
    }
}
');

writeClass('app/Broadcasting/FcmChannel.php', '<?php
namespace App\Broadcasting;
use Illuminate\Notifications\Notification;
use App\Services\FcmService;
class FcmChannel {
    protected $fcmService;
    public function __construct(FcmService $fcmService) {
        $this->fcmService = $fcmService;
    }
    public function send($notifiable, Notification $notification) {
        if (!method_exists($notification, "toFcm")) {
            return;
        }
        $payload = $notification->toFcm($notifiable);
        $this->fcmService->sendToUser($notifiable, $payload);
    }
}
');

@mkdir($base . '/app/Broadcasting', 0755, true);

writeClass('app/Notifications/SevereExamResultNotification.php', '<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\AttemptResult;
use App\Broadcasting\FcmChannel;
use App\Models\ChatThread;

class SevereExamResultNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        $patient = $this->result->attempt->patient;
        return (new MailMessage)
            ->subject("Severe Result Alert")
            ->line("A severe exam result was just submitted by {$patient->name}.")
            ->action("View Profile / Chat", url("/admin/chat/{$patient->id}"));
    }
    public function toArray(object $notifiable): array {
        $patient = $this->result->attempt->patient;
        $thread = ChatThread::firstOrCreate(["patient_id" => $patient->id]);
        return [
            "message" => "Severe result alert for {$patient->name}",
            "patient_id" => $patient->id,
            "thread_id" => $thread->id,
            "result_id" => $this->result->id,
        ];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Severe Result Alert", "body" => $this->toArray($notifiable)["message"]];
    }
}
');

writeClass('app/Notifications/ExamResultReadyNotification.php', '<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\AttemptResult;
use App\Broadcasting\FcmChannel;

class ExamResultReadyNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->subject("Your Check-in Result is Ready")
            ->line("Your recent check-in result is ready — your care team may reach out if needed.")
            ->action("View Result", url("/patient/results/{$this->result->id}"));
    }
    public function toArray(object $notifiable): array {
        return [
            "message" => "Your recent check-in result is ready — your care team may reach out.",
            "result_id" => $this->result->id,
        ];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Result Ready", "body" => "Your recent check-in result is ready."];
    }
}
');

writeClass('app/Notifications/NewChatMessageNotification.php', '<?php
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
');

echo "Events and Notifications populated.\n";
