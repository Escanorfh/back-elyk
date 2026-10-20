<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAttempt extends Model
{
    protected $guarded = [];


    //
    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function answers()
    {
        return $this->hasMany(ExamAnswer::class, 'attempt_id');
    }

    public function subscaleResults()
    {
        return $this->hasMany(AttemptSubscaleResult::class, 'attempt_id');
    }

    public function result()
    {
        return $this->hasOne(AttemptResult::class, 'attempt_id');
    }
}
