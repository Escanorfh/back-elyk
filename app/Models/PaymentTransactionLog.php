<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// Immutable audit trail for payment gateway events (webhooks, verification, refunds).
class PaymentTransactionLog extends Model {
    public $timestamps = false;
    protected $table = "payment_transactions_log";
    protected $guarded = [];
    protected $casts = [
        "payload" => "array",
        "created_at" => "datetime",
    ];
    public function payment() { return $this->belongsTo(LiveSessionPayment::class, "payment_id"); }
}
