<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Events\AttemptFinalized;
use App\Listeners\AdvanceIntakeBattery;
use App\Listeners\DraftPatientPath;
use App\Listeners\NotifyMaxLevelBreach;
use App\Listeners\NotifyPatientResultReady;
use App\Listeners\NotifySevereResult;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // نتيجة أي فورم (points => disorder) تشغّل: تقدّم الـ intake،
        // مسودة مسار (لو المرض مكتشف)، إشعار المريض، إشعار الشدة،
        // والإشعار الأحمر الفوري لتجاوز الـ max level.
        Event::listen(AttemptFinalized::class, AdvanceIntakeBattery::class);
        Event::listen(AttemptFinalized::class, DraftPatientPath::class);
        Event::listen(AttemptFinalized::class, NotifyPatientResultReady::class);
        Event::listen(AttemptFinalized::class, NotifySevereResult::class);
        Event::listen(AttemptFinalized::class, NotifyMaxLevelBreach::class);

        // Require API docs routes if they exist
        if (file_exists(base_path('routes/docs.php'))) {
            require base_path('routes/docs.php');
        }

        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(
            function (\Laravel\Sanctum\PersonalAccessToken $accessToken, bool $isValid) {
                $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;
                return $isValid && $lastActivity && $lastActivity->gte(now()->subDays(60));
            }
        );
    }
}
