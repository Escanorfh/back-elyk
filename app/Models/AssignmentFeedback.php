<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AssignmentFeedback extends Model {
    protected $fillable = ["assignment_id", "admin_id", "note_text", "file_path", "is_visible_to_patient"];
    protected $casts = ["is_visible_to_patient" => "boolean"];
    public function assignment() { return $this->belongsTo(Assignment::class); }
    public function admin() { return $this->belongsTo(User::class, "admin_id"); }
}
