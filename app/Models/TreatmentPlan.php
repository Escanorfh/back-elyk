<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    protected $fillable = [
        'disorder_id',
        'order',
        'title',
        'type',
        'description',
        'audio_media_id',
        'pdf_media_id',
        'has_assignment',
    ];

    protected $casts = [
        'has_assignment' => 'boolean',
    ];

    public function disorder()
    {
        return $this->belongsTo(Disorder::class);
    }

    public function audioMedia()
    {
        return $this->belongsTo(MediaItem::class, 'audio_media_id');
    }

    public function pdfMedia()
    {
        return $this->belongsTo(MediaItem::class, 'pdf_media_id');
    }
}
