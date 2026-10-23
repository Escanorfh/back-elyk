<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaItem extends Model {
    protected $fillable = ["disorder_id", "type", "stage", "title", "title_ar", "description", "file_path", "duration_seconds", "uploaded_by", "is_active"];
    protected $casts = ["is_active" => "boolean", "stage" => "integer", "duration_seconds" => "integer"];
    public function disorder() { return $this->belongsTo(Disorder::class); }
    public function uploader() { return $this->belongsTo(User::class, "uploaded_by"); }
}
