<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscale extends Model
{
    protected $guarded = [];


    //
    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function severityBands()
    {
        return $this->hasMany(SeverityBand::class);
    }
}
