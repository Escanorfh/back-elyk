<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Disorder;
use App\Models\TreatmentPlan;
use App\Models\PatientPath;
use App\Models\PathStep;
use Illuminate\Http\Request;

class TreatmentPlanController extends Controller
{
    // ─── عرض خطة العلاج ───

    public function index(Disorder $disorder)
    {
        $plans = $disorder->treatmentPlans()->with(['audioMedia', 'pdfMedia'])->get();
        return response()->json(['data' => $plans]);
    }

    public function show(TreatmentPlan $plan)
    {
        $plan->load(['audioMedia', 'pdfMedia']);
        return response()->json(['data' => $plan]);
    }

    // ─── إنشاء / تحديث الخطة ───
    // كل مرحلة (stage) فيها: عنوان + voice (audio_media_id) + report (pdf_media_id) + تكليف اختياري.
    // يقبل أيضاً الشكل القديم (type: voice|pdf|assignment) للتوافق الخلفي.

    public function store(Request $request, Disorder $disorder)
    {
        $validated = $request->validate([
            'stages' => 'required|array|min:1',
            'stages.*.order'   => 'required|integer|min:1',
            'stages.*.title'   => 'required|string|max:500',
            'stages.*.description' => 'nullable|string',
            // الشكل الجديد: voice + report معاً لكل مرحلة
            'stages.*.audio_media_id' => 'nullable|integer|exists:media_items,id',
            'stages.*.pdf_media_id'   => 'nullable|integer|exists:media_items,id',
            'stages.*.has_assignment' => 'sometimes|boolean',
            // الشكل القديم (اختياري للتوافق)
            'stages.*.type'    => 'nullable|string|in:voice,pdf,assignment',
        ]);

        foreach ($validated['stages'] as $stage) {
            foreach (['audio_media_id', 'pdf_media_id'] as $key) {
                if (!empty($stage[$key])) {
                    $media = \App\Models\MediaItem::find($stage[$key]);
                    if (!$media || (int) $media->disorder_id !== (int) $disorder->id) {
                        abort(422, 'الوسيلة المختارة لا تنتمي لهذا المرض.');
                    }
                }
            }
            if (empty($stage['title']) && empty($stage['audio_media_id']) && empty($stage['pdf_media_id'])) {
                abort(422, 'كل مرحلة يجب أن تحتوي على عنوان أو voice أو report.');
            }
        }

        // حذف الخطة القديمة وإنشاء جديدة
        $disorder->treatmentPlans()->delete();

        foreach ($validated['stages'] as $stage) {
            $disorder->treatmentPlans()->create([
                'order'          => $stage['order'],
                'title'          => $stage['title'],
                'type'           => $this->resolveLegacyType($stage),
                'description'    => $stage['description'] ?? null,
                'audio_media_id' => $stage['audio_media_id'] ?? null,
                'pdf_media_id'   => $stage['pdf_media_id'] ?? null,
                'has_assignment' => array_key_exists('has_assignment', $stage)
                    ? (bool) $stage['has_assignment']
                    : true,
            ]);
        }

        $plans = $disorder->treatmentPlans()->with(['audioMedia', 'pdfMedia'])->get();
        return response()->json(['data' => $plans, 'message' => 'تم حفظ خطة العلاج']);
    }

    public function update(Request $request, TreatmentPlan $plan)
    {
        $validated = $request->validate([
            'title'          => 'sometimes|string|max:500',
            'type'           => 'sometimes|string|in:voice,pdf,assignment',
            'description'    => 'nullable|string',
            'order'          => 'sometimes|integer|min:1',
            'audio_media_id' => 'nullable|integer|exists:media_items,id',
            'pdf_media_id'   => 'nullable|integer|exists:media_items,id',
            'has_assignment' => 'sometimes|boolean',
        ]);

        foreach (['audio_media_id', 'pdf_media_id'] as $key) {
            if (array_key_exists($key, $validated) && !empty($validated[$key])) {
                $media = \App\Models\MediaItem::find($validated[$key]);
                if (!$media || (int) $media->disorder_id !== (int) $plan->disorder_id) {
                    abort(422, 'الوسيلة المختارة لا تنتمي لهذا المرض.');
                }
            }
        }

        $plan->update($validated);
        $plan->load(['audioMedia', 'pdfMedia']);
        return response()->json(['data' => $plan]);
    }

    public function destroy(TreatmentPlan $plan)
    {
        $plan->delete();
        return response()->json(['message' => 'تم حذف المرحلة']);
    }

    // ─── تعيين المريض — إنشاء مسار علاجي تلقائي من الخطة ───

    public function assign(Request $request, Disorder $disorder)
    {
        $validated = $request->validate([
            'patient_id' => 'required|exists:users,id',
        ]);

        $patientId = $validated['patient_id'];

        // التحقق من عدم وجود مسار سابق لهذا المريض بهذا المرض
        $existing = PatientPath::where('patient_id', $patientId)
            ->where('disorder_id', $disorder->id)
            ->first();

        if ($existing) {
            abort(400, 'هذا المريض لديه مسار علاجي بالفعل لهذا المرض.');
        }

        // جلب الخطة العلاجية
        $plans = $disorder->treatmentPlans()->orderBy('order')->get();

        if ($plans->isEmpty()) {
            abort(400, 'لا توجد خطة علاجية لهذا المرض. قم بإنشائها أولاً.');
        }

        // إنشاء المسار
        $path = PatientPath::create([
            'patient_id'  => $patientId,
            'disorder_id' => $disorder->id,
            'status'      => 'draft',
        ]);

        // جلب الوسائط المتاحة لكل مرحلة
        $mediaByStage = $disorder->mediaItems()
            ->where('is_active', true)
            ->get()
            ->groupBy('stage');

        // إنشاء الخطوات من الخطة (القالب الجاهز للمرض)
        foreach ($plans as $plan) {
            // الأولوية للربط الصريح المحفوظ على المرحلة (voice + report معاً)
            $audioMediaId = $plan->audio_media_id;
            $pdfMediaId   = $plan->pdf_media_id;

            // توافق خلفي: لو المرحلة قديمة (type مفرد بدون ربط صريح)
            // نبحث عن ملف صوتي/PDF في نفس رقم المرحلة
            if (empty($audioMediaId) && empty($pdfMediaId)) {
                if ($plan->type === 'voice') {
                    // المرحلة الصوتية: نبحث عن ملف صوتي في نفس رقم المرحلة
                    $audioItems = $mediaByStage->get($plan->order, collect())
                        ->where('type', 'audio');
                    if ($audioItems->isNotEmpty()) {
                        $audioMediaId = $audioItems->first()->id;
                    }
                } elseif ($plan->type === 'pdf') {
                    // مرحلة التقرير: نبحث عن PDF في نفس رقم المرحلة
                    $pdfItems = $mediaByStage->get($plan->order, collect())
                        ->where('type', 'pdf');
                    if ($pdfItems->isNotEmpty()) {
                        $pdfMediaId = $pdfItems->first()->id;
                    }
                }
            }
            // type === 'assignment' لا يحتاج وسائط

            $path->steps()->create([
                'order'           => $plan->order,
                'title'           => $plan->title,
                'audio_media_id'  => $audioMediaId,
                'pdf_media_id'    => $pdfMediaId,
                'status'          => 'pending',
            ]);
        }

        // فتح أول مرحلة فوراً (نفس سلوك البناء التلقائي عند الكشف).
        $first = $path->steps()->orderBy('order')->first();
        if ($first) {
            $first->update(['status' => 'active', 'unlocked_at' => now()]);
            $path->update(['status' => 'active', 'current_step_id' => $first->id]);
            if ($path->patient) {
                $path->patient->notify(new \App\Notifications\StepReleasedNotification($first));
            }
        }

        $path->load('steps', 'disorder');
        return response()->json([
            'data'    => $path,
            'message' => 'تم إنشاء المسار العلاجي بنجاح',
        ], 201);
    }

    /**
     * اشتقاق type القديم من محتوى المرحلة (للتوافق مع العملاء القدامى).
     * voice+report معاً => 'voice' (الخطوة تدعم الاثنين في PathStep).
     */
    private function resolveLegacyType(array $stage): string
    {
        if (!empty($stage['type'])) {
            return $stage['type'];
        }
        $hasAudio = !empty($stage['audio_media_id']);
        $hasPdf = !empty($stage['pdf_media_id']);
        if ($hasAudio) return 'voice';
        if ($hasPdf) return 'pdf';
        return 'assignment';
    }
}
