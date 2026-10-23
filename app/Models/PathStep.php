<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PathStep extends Model {
    protected $fillable = ["patient_path_id", "order", "title", "audio_media_id", "pdf_media_id", "status", "unlocked_at", "submitted_at", "completed_at"];
    protected $casts = ["unlocked_at" => "datetime", "submitted_at" => "datetime", "completed_at" => "datetime"];
    public function path() { return $this->belongsTo(PatientPath::class, "patient_path_id"); }
    public function audioMedia() { return $this->belongsTo(MediaItem::class, "audio_media_id"); }
    public function pdfMedia() { return $this->belongsTo(MediaItem::class, "pdf_media_id"); }
    public function assignment() { return $this->hasOne(Assignment::class); }
}
