<?php
namespace App\Events;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\LiveRaiseHandRequest;

class LiveRaiseHandEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $request;
    public function __construct(LiveRaiseHandRequest $request) {
        $this->request = $request->load("participant");
    }
    public function broadcastOn(): array {
        return [new PrivateChannel("live." . $this->request->live_session_id . ".admin")];
    }
    public function broadcastAs(): string { return "raise.hand"; }
}
