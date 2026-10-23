<?php

namespace App\Events;

use App\Models\LiveSessionPayment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LiveSessionPayment $payment) {}

    /**
     * Wire name: private-user.{patientId}.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->payment->patient_id)];
    }

    public function broadcastAs(): string
    {
        return 'payment.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'payment_status' => $this->payment->status,
            'is_paid' => $this->payment->status === 'paid',
            'merchant_reference' => $this->payment->merchant_order_reference,
            'live_session_id' => $this->payment->live_session_id,
            'amount' => (int) $this->payment->amount,
            'currency' => $this->payment->currency,
            'payment_method' => $this->payment->payment_method,
        ];
    }
}
