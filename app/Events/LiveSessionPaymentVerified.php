<?php

namespace App\Events;

use App\Models\LiveSessionPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveSessionPaymentVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public LiveSessionPayment $payment) {}
}
