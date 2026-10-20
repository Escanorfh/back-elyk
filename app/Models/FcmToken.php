<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FcmToken extends Model {
    protected $fillable = ["user_id", "token", "device_type", "last_used_at"];
    protected $casts = ["last_used_at" => "datetime"];
    public function user() { return $this->belongsTo(User::class); }
}
