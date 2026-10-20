<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeverityBand extends Model
{
    protected $guarded = [];


    //
    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function subscale()
    {
        return $this->belongsTo(Subscale::class);
    }
}
