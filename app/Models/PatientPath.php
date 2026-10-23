<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PatientPath extends Model {
    protected $fillable = ["patient_id", "disorder_id", "status", "current_step_id"];
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function disorder() { return $this->belongsTo(Disorder::class); }
    public function currentStep() { return $this->belongsTo(PathStep::class, "current_step_id"); }
    public function steps() { return $this->hasMany(PathStep::class)->orderBy("order", "asc"); }
}
