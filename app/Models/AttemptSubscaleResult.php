<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptSubscaleResult extends Model
{
    protected $guarded = [];


    //
    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function subscale()
    {
        return $this->belongsTo(Subscale::class);
    }

    public function severityBand()
    {
        return $this->belongsTo(SeverityBand::class);
    }
}
