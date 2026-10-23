<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntakeBatteryItem extends Model
{
    protected $fillable = ['intake_battery_id', 'exam_id', 'order', 'status', 'attempt_id'];

    public function battery() {
        return $this->belongsTo(IntakeBattery::class, 'intake_battery_id');
    }

    public function exam() {
        return $this->belongsTo(Exam::class);
    }

    public function attempt() {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }
}
