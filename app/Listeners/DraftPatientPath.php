<?php

namespace App\Listeners;

use App\Events\AttemptFinalized;
use App\Models\PatientPath;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Notifications\PathNeedsAssemblyNotification;
use App\Notifications\StepReleasedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;

/**
 * بناء المسار العلاجي تلقائياً لحظة اكتشاف المرض:
 * - لو المريض عدّى الـ min level (disorder_detected) وملوش مسار للمرض ده،
 *   يتبني المسار من قالب المرض (treatment_plans) بكل مراحله:
 *   عنوان + voice + report + تكليف، وتتفتح أول مرحلة فوراً.
 * - لو المرض ملهوش قالب جاهز => مسودة + إشعار للأدمن للتجميع اليدوي.
 * - لو المسار موجود أصلاً => لا شيء (منع التكرار).
 *
 * متعمد synchronous (بدون ShouldQueue) عشان المراحل تتفتح فور التسليم.
 */
class DraftPatientPath
{
    public function handle(AttemptFinalized $event): void
    {
        $result = $event->result;

        // لا مسار إلا لو المريض عدّى الـ min level (عنده المرض فعلاً)
        if (!$result->disorder_detected) return;

        $patient = $result->attempt->patient;
        if (!$patient) return;
        $disorderId = $result->disorder_id;

        try {
            $path = PatientPath::firstOrCreate(
                ['patient_id' => $patient->id, 'disorder_id' => $disorderId],
                ['status' => 'draft']
            );
        } catch (QueryException $e) {
            // سباق مع مسار اتعمل في نفس اللحظة — لا شيء يُعمل.
            return;
        }

        if (!$path->wasRecentlyCreated) return;

        $plans = TreatmentPlan::where('disorder_id', $disorderId)
            ->orderBy('order')
            ->get();

        // مفيش قالب جاهز => السلوك القديم: مسودة + تنبيه الأدمن.
        if ($plans->isEmpty()) {
            $admins = User::where('role', 'admin')->get();
            Notification::send($admins, new PathNeedsAssemblyNotification($path));
            return;
        }

        // بناء المراحل من القالب: عنوان + voice + report لكل مرحلة.
        $first = null;
        foreach ($plans as $plan) {
            $step = $path->steps()->create([
                'order' => $plan->order,
                'title' => $plan->title,
                'audio_media_id' => $plan->audio_media_id,
                'pdf_media_id' => $plan->pdf_media_id,
                'status' => 'pending',
            ]);
            if ($first === null) $first = $step;
        }

        // فتح أول مرحلة فوراً + إشعار المريض.
        if ($first) {
            $first->update(['status' => 'active', 'unlocked_at' => now()]);
            $path->update(['status' => 'active', 'current_step_id' => $first->id]);
            $patient->notify(new StepReleasedNotification($first));
        }
    }
}
