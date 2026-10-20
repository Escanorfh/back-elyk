<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LiveSessionPayment extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        "paid_at" => "datetime",
        "expires_at" => "datetime",
        "gateway_response" => "array",
    ];
    public function session() { return $this->belongsTo(LiveSession::class, "live_session_id"); }
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function logs() { return $this->hasMany(PaymentTransactionLog::class, "payment_id"); }
}
