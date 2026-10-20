<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Assignment extends Model {
    protected $fillable = ["path_step_id", "patient_id", "text_response", "file_path", "submitted_at"];
    protected $casts = ["submitted_at" => "datetime"];
    public function step() { return $this->belongsTo(PathStep::class, "path_step_id"); }
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function feedback() { return $this->hasMany(AssignmentFeedback::class); }
}
