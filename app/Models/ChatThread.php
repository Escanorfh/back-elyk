<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatThread extends Model {
    protected $fillable = ["patient_id"];
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function messages() { return $this->hasMany(ChatMessage::class, "thread_id"); }
}
