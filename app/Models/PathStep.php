<?php
namespace App\Models;
use App\Support\CalendarTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
class PathStep extends Model {
    protected $fillable = ["patient_path_id", "order", "title", "audio_media_id", "pdf_media_id", "status", "unlocked_at", "submitted_at", "completed_at", "due_at", "due_timezone", "priority", "category", "description", "assigned_by"];
    protected $casts = ["unlocked_at" => "datetime", "submitted_at" => "datetime", "completed_at" => "datetime", "due_at" => "datetime"];
    public function path() { return $this->belongsTo(PatientPath::class, "patient_path_id"); }
    public function audioMedia() { return $this->belongsTo(MediaItem::class, "audio_media_id"); }
    public function pdfMedia() { return $this->belongsTo(MediaItem::class, "pdf_media_id"); }
    public function assignment() { return $this->hasOne(Assignment::class); }
    public function assignedBy() { return $this->belongsTo(User::class, "assigned_by"); }
    public function reminders() { return $this->morphMany(Reminder::class, "notifiable"); }

    // due_at لحظة داخل [from, to) — القاعدة في App\Support\CalendarTime وحدها.
    public function scopeDueInRange(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder {
        return CalendarTime::whereOverlaps($query, "due_at", null, $from, $to);
    }
}
