<?php

namespace App\Events;

use App\Models\LiveSessionPatient;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminApprovalQueueUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $pendingCount,
        public ?int $liveSessionId = null,
        public ?string $action = null,
    ) {}

    /**
     * Wire name: private-admin-approvals.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin-approvals')];
    }

    public function broadcastAs(): string
    {
        return 'approvals.queue';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'pending_count' => $this->pendingCount,
            'live_session_id' => $this->liveSessionId,
            'action' => $this->action,
        ];
    }

    /**
     * Current number of pending_approval invites backed by a paid charge.
     */
    public static function pendingCount(): int
    {
        return LiveSessionPatient::where('approval_status', 'pending_approval')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('live_session_payments')
                    ->whereColumn('live_session_payments.live_session_id', 'live_session_patients.live_session_id')
                    ->whereColumn('live_session_payments.patient_id', 'live_session_patients.patient_id')
                    ->where('live_session_payments.status', 'paid');
            })
            ->count();
    }
}
