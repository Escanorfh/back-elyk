<?php

namespace App\Models;

use App\Support\CalendarTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSettings extends Model
{
    protected $fillable = [
        'user_id',
        'email_notifications',
        'push_notifications',
        'session_reminders',
        'language',
        'theme',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'session_reminders' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * NULL هنا تعني «لم يُعلَن تفضيل» ولا تعني UTC أبدًا؛ السقوط الآمن إلى إعداد التطبيق.
     * تُطبَّق عند الإنشاء فقط — العرض يستخدم منطقة الجهاز.
     */
    public function declaredTimezone(): string
    {
        return CalendarTime::timezoneOf($this->timezone);
    }
}
