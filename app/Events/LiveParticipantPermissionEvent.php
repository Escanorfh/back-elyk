<?php
namespace App\Events;

use App\Models\LiveParticipant;
use App\Models\LiveSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * تغيّر صلاحية الكلام لمشارك (فتح/قفل المايك) حيًا.
 *
 * يُبث على قناة الجلسة المشتركة (للمشاركين) وقناة الأدمن (للتوافق) معًا.
 * broadcastWith مقصود: بلا user_id ولا بيانات حساسة — المشارك يميّز نفسه بـ participant_id.
 */
class LiveParticipantPermissionEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveSession $session,
        public LiveParticipant $participant,
        public bool $canPublishAudio,
        public ?bool $livekitUpdated,
    ) {}

    public function broadcastOn(): array {
        return [
            new PrivateChannel("live." . $this->session->id),
            new PrivateChannel("live." . $this->session->id . ".admin"),
        ];
    }

    public function broadcastAs(): string { return "participant.permission"; }

    public function broadcastWith(): array {
        return [
            "session_id" => $this->session->id,
            "participant_id" => $this->participant->id,
            "can_publish_audio" => $this->canPublishAudio,
            "livekit_updated" => $this->livekitUpdated,
            "at" => now()->toIso8601String(),
        ];
    }
}
