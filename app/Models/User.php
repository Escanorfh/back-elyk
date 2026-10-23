<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'photo_url',
        'background_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    public function examAttempts()
    {
        return $this->hasMany(ExamAttempt::class, 'patient_id');
    }

    public function fcmTokens() { return $this->hasMany(FcmToken::class); }
    public function chatThread() { return $this->hasOne(ChatThread::class, "patient_id"); }
    public function messages() { return $this->hasMany(ChatMessage::class, "sender_id"); }

    public function patientPaths() { return $this->hasMany(PatientPath::class, "patient_id"); }
    public function assignments() { return $this->hasMany(Assignment::class, "patient_id"); }
    public function settings() { return $this->hasOne(UserSettings::class); }
}