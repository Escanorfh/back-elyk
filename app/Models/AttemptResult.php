<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptResult extends Model
{
    protected $guarded = [];


    //
    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function disorder()
    {
        return $this->belongsTo(Disorder::class);
    }

    public function severityBand()
    {
        return $this->belongsTo(SeverityBand::class);
    }
}
