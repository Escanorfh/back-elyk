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
        return [
            // قناة الأدمن (طلب رفع اليد موجّه للأدمن) + قناة الجلسة المشتركة
            // حتى يعرف الجميع من رفع يده فورًا.
            new PrivateChannel("live." . $this->request->live_session_id . ".admin"),
            new PrivateChannel("live." . $this->request->live_session_id),
        ];
    }
    public function broadcastAs(): string { return "raise.hand"; }

    public function broadcastWith(): array {
        return [
            "request_id" => $this->request->id,
            "live_session_id" => $this->request->live_session_id,
            "participant_id" => $this->request->participant_id,
            "status" => $this->request->status,
            "sender_label" => $this->request->participant?->anonymous_label,
            "created_at" => optional($this->request->created_at)->toISOString(),
        ];
    }
}
