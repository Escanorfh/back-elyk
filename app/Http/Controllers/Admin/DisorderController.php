<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Disorder;
use App\Http\Requests\StoreDisorderRequest;
use Illuminate\Support\Facades\DB;
class DisorderController extends Controller {
    public function index() {
        $disorders = Disorder::with(['treatmentPlans.audioMedia', 'treatmentPlans.pdfMedia'])->get();
        return response()->json(["data" => $disorders]);
    }
    public function store(StoreDisorderRequest $request) {
        $data = $request->validated();
        $stages = $data['stages'] ?? null;
        unset($data['stages']);

        $disorder = DB::transaction(function () use ($data, $stages) {
            $disorder = Disorder::create($data);
            if (!empty($stages)) {
                $this->syncStages($disorder, $stages);
            }
            return $disorder;
        });

        $disorder->load(['treatmentPlans.audioMedia', 'treatmentPlans.pdfMedia']);
        return response()->json(["data" => $disorder], 201);
    }
    public function show(Disorder $disorder) {
        $disorder->load(['treatmentPlans.audioMedia', 'treatmentPlans.pdfMedia', 'mediaItems']);
        return response()->json(["data" => $disorder]);
    }
    public function update(StoreDisorderRequest $request, Disorder $disorder) {
        $data = $request->validated();
        $stages = array_key_exists('stages', $data) ? $data['stages'] : null;
        unset($data['stages']);

        DB::transaction(function () use ($disorder, $data, $stages) {
            $disorder->update($data);
            if (is_array($stages)) {
                $this->syncStages($disorder, $stages);
            }
        });

        $disorder->load(['treatmentPlans.audioMedia', 'treatmentPlans.pdfMedia']);
        return response()->json(["data" => $disorder]);
    }
    public function destroy(Disorder $disorder) {
        $disorder->delete();
        return response()->json(["message" => "Deleted"]);
    }

    /**
     * حفظ قالب المراحل للمرض: كل مرحلة = عنوان + voice + report.
     * تُستبدل الخطة القديمة بالكامل (نفس سلوك TreatmentPlanController@store).
     */
    private function syncStages(Disorder $disorder, array $stages): void
    {
        foreach ($stages as $stage) {
            foreach (['audio_media_id', 'pdf_media_id'] as $key) {
                if (!empty($stage[$key])) {
                    $media = \App\Models\MediaItem::find($stage[$key]);
                    if (!$media || (int) $media->disorder_id !== (int) $disorder->id) {
                        abort(422, 'الوسيلة المختارة لا تنتمي لهذا المرض.');
                    }
                }
            }
        }

        $disorder->treatmentPlans()->delete();

        foreach ($stages as $stage) {
            $hasAudio = !empty($stage['audio_media_id']);
            $hasPdf = !empty($stage['pdf_media_id']);
            $type = $stage['type'] ?? ($hasAudio ? 'voice' : ($hasPdf ? 'pdf' : 'assignment'));

            $disorder->treatmentPlans()->create([
                'order'          => $stage['order'],
                'title'          => $stage['title'],
                'type'           => $type,
                'description'    => $stage['description'] ?? null,
                'audio_media_id' => $stage['audio_media_id'] ?? null,
                'pdf_media_id'   => $stage['pdf_media_id'] ?? null,
                'has_assignment' => array_key_exists('has_assignment', $stage)
                    ? (bool) $stage['has_assignment']
                    : true,
            ]);
        }
    }
}
