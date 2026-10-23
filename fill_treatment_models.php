<?php
$base = __DIR__;

function writeModel($file, $content) {
    global $base;
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeModel('app/Models/MediaItem.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaItem extends Model {
    protected $fillable = ["disorder_id", "type", "title", "title_ar", "description", "file_path", "duration_seconds", "uploaded_by", "is_active"];
    protected $casts = ["is_active" => "boolean"];
    public function disorder() { return $this->belongsTo(Disorder::class); }
    public function uploader() { return $this->belongsTo(User::class, "uploaded_by"); }
}
');

writeModel('app/Models/PatientPath.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PatientPath extends Model {
    protected $fillable = ["patient_id", "disorder_id", "status", "current_step_id"];
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function disorder() { return $this->belongsTo(Disorder::class); }
    public function currentStep() { return $this->belongsTo(PathStep::class, "current_step_id"); }
    public function steps() { return $this->hasMany(PathStep::class)->orderBy("order", "asc"); }
}
');

writeModel('app/Models/PathStep.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PathStep extends Model {
    protected $fillable = ["patient_path_id", "order", "audio_media_id", "pdf_media_id", "status", "unlocked_at", "submitted_at", "completed_at"];
    protected $casts = ["unlocked_at" => "datetime", "submitted_at" => "datetime", "completed_at" => "datetime"];
    public function path() { return $this->belongsTo(PatientPath::class, "patient_path_id"); }
    public function audioMedia() { return $this->belongsTo(MediaItem::class, "audio_media_id"); }
    public function pdfMedia() { return $this->belongsTo(MediaItem::class, "pdf_media_id"); }
    public function assignment() { return $this->hasOne(Assignment::class); }
}
');

writeModel('app/Models/Assignment.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Assignment extends Model {
    protected $fillable = ["path_step_id", "patient_id", "text_response", "file_path", "submitted_at"];
    protected $casts = ["submitted_at" => "datetime"];
    public function step() { return $this->belongsTo(PathStep::class, "path_step_id"); }
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function feedback() { return $this->hasMany(AssignmentFeedback::class); }
}
');

writeModel('app/Models/AssignmentFeedback.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AssignmentFeedback extends Model {
    protected $fillable = ["assignment_id", "admin_id", "note_text", "file_path", "is_visible_to_patient"];
    protected $casts = ["is_visible_to_patient" => "boolean"];
    public function assignment() { return $this->belongsTo(Assignment::class); }
    public function admin() { return $this->belongsTo(User::class, "admin_id"); }
}
');

// Update Disorder Model
$disorderModel = file_get_contents($base . '/app/Models/Disorder.php');
if (strpos($disorderModel, 'mediaItems') === false) {
    $methods = '
    public function mediaItems() { return $this->hasMany(MediaItem::class); }
    public function patientPaths() { return $this->hasMany(PatientPath::class); }
}';
    $disorderModel = preg_replace('/}\s*$/', $methods, $disorderModel);
    file_put_contents($base . '/app/Models/Disorder.php', $disorderModel);
}

// Update User Model
$userModel = file_get_contents($base . '/app/Models/User.php');
if (strpos($userModel, 'patientPaths') === false) {
    $methods = '
    public function patientPaths() { return $this->hasMany(PatientPath::class, "patient_id"); }
    public function assignments() { return $this->hasMany(Assignment::class, "patient_id"); }
}';
    $userModel = preg_replace('/}\s*$/', $methods, $userModel);
    file_put_contents($base . '/app/Models/User.php', $userModel);
}

echo "Models populated.\n";
