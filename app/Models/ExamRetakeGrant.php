<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ExamRetakeGrant extends Model {
    protected $fillable = ["patient_id", "exam_id", "granted_at"];
    protected $casts = ["granted_at" => "datetime"];
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function exam() { return $this->belongsTo(Exam::class, "exam_id"); }
}