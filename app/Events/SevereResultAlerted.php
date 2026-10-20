<?php
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
