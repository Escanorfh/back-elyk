<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $guarded = [];


    //
    public function disorder()
    {
        return $this->belongsTo(Disorder::class);
    }

    public function subscales()
    {
        return $this->hasMany(Subscale::class);
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
