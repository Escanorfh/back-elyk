<?php
namespace App\Events;

use App\Models\LiveRaiseHandRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * حسم طلب رفع يد (موافقة/رفض) — يصل صاحب الطلب وكل الجلسة فورًا.
 *
 * livekit_updated: نتيجة تحديث صلاحية LiveKit الفعلية (null عندما لا يوجد
 * نداء LiveKit — مثل الرفض). الفصل بين موافقة DB وتحديث LiveKit مقصود.
 */
class LiveHandResolvedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveRaiseHandRequest $request,
        public string $status, // approved | denied
        public ?bool $livekitUpdated,
    ) {}

    public function broadcastOn(): array {
        return [
            new PrivateChannel("live." . $this->request->live_session_id),
            new PrivateChannel("live." . $this->request->live_session_id . ".admin"),
        ];
    }

    public function broadcastAs(): string { return "hand.resolved"; }

    public function broadcastWith(): array {
        return [
            "session_id" => $this->request->live_session_id,
            "request_id" => $this->request->id,
            "participant_id" => $this->request->participant_id,
            "status" => $this->status,
            "can_publish_audio" => $this->status === "approved",
            "livekit_updated" => $this->livekitUpdated,
            "at" => now()->toIso8601String(),
        ];
    }
}
