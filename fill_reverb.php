<?php
$base = __DIR__;

function writeClass($file, $content) {
    global $base;
    $dir = dirname($base . '/' . $file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeClass('app/Events/ChatMessageSent.php', '<?php
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
');

writeClass('app/Events/SevereResultAlerted.php', '<?php
namespace App\Events;
use App\Models\AttemptResult;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SevereResultAlerted implements ShouldBroadcast {
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
    public function broadcastOn(): array {
        return [
            new PrivateChannel("admins")
        ];
    }
    public function broadcastWith(): array {
        $patient = $this->result->attempt->patient;
        return [
            "message" => "Severe result alert for {$patient->name}",
            "patient_id" => $patient->id,
            "result_id" => $this->result->id,
        ];
    }
}
');

// Update channels.php
$channels = '<?php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel("App.Models.User.{id}", function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel("chat.thread.{id}", function ($user, $id) {
    if ($user->role === "admin") return true;
    $thread = \App\Models\ChatThread::find($id);
    return $thread && (int) $user->id === (int) $thread->patient_id;
});

Broadcast::channel("admins", function ($user) {
    return $user->role === "admin";
});
';
file_put_contents($base . '/routes/channels.php', $channels);

echo "Reverb Events populated.\n";
