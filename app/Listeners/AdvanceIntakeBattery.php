<?php

namespace App\Listeners;

use App\Events\AttemptFinalized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Models\IntakeBattery;
use App\Models\User;
use App\Notifications\ExamResultReadyNotification;
use App\Notifications\AdminExamResultNotification;

class AdvanceIntakeBattery
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(AttemptFinalized $event): void
    {
        $result = $event->result;
        $attempt = $result->attempt;
        $patient = $attempt->patient;

        // Fire notifications for ALL finalized attempts
        $patient->notify(new ExamResultReadyNotification($result));
        
        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            $admin->notify(new AdminExamResultNotification($result));
        }

        // Check if there is an in-progress battery
        $battery = IntakeBattery::where('patient_id', $patient->id)
            ->where('status', 'in_progress')
            ->first();

        if (!$battery) return;

        // المريض اختار مقياس واحد وخلّصه — ده كافي لإتمام الفحص الأولي.
        // باقي المقاييس تفضل متاحة كاختيار حر من شاشة التقييمات.
        $item = $battery->items()->where('exam_id', $attempt->exam_id)->first();
        if ($item) {
            $item->update([
                'status' => 'completed',
                'attempt_id' => $attempt->id
            ]);
        }

        $battery->update([
            'status' => 'completed',
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);
        $patient->update(['intake_completed_at' => now()]);
    }
}
