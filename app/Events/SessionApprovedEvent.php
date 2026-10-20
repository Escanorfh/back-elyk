<?php

namespace App\Events;

use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionApprovedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LiveSession $session, public User $patient) {}

    /**
     * Wire name: private-user.{patientId}.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->patient->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'session.approved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'approval_status' => 'approved',
            'live_session_id' => $this->session->getKey(),
            'approved_at' => now()->toISOString(),
        ];
    }
}
