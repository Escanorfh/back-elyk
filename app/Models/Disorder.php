<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disorder extends Model
{
    protected $guarded = [];

    public function exams()          { return $this->hasMany(Exam::class); }
    public function mediaItems()     { return $this->hasMany(MediaItem::class); }
    public function patientPaths()   { return $this->hasMany(PatientPath::class); }
    public function treatmentPlans() { return $this->hasMany(TreatmentPlan::class)->orderBy('order'); }
}
