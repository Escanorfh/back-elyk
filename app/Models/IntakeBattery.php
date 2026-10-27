<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntakeBattery extends Model
{
    protected $fillable = ['patient_id', 'status', 'started_at', 'completed_at', 'last_activity_at', 'last_reminded_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function patient() {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function items() {
        return $this->hasMany(IntakeBatteryItem::class)->orderBy('order');
    }

    public function currentItem() {
        return $this->hasOne(IntakeBatteryItem::class)->where('status', 'active');
    }
}
