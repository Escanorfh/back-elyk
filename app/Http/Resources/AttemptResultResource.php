<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
class AttemptResultResource extends JsonResource {
    public function toArray($request) {
        $exam = $this->attempt->exam()->with("severityBands")->first();
        $maxScore = ($exam && $exam->severityBands->isNotEmpty())
            ? $exam->severityBands->max("max_score")
            : null;

        return [
            "id" => $this->id,
            "disorder" => $this->disorder->name ?? null,
            "disorder_ar" => $this->disorder->name_ar ?? $this->disorder->name ?? null,
            // هل المريض عدّى الـ min level => عنده المرض ده (وإلا النقاط تحت الحد)
            "disorder_detected" => (bool) ($this->disorder_detected ?? true),
            "min_level_score" => $exam->min_level_score ?? null,
            "max_level_score" => $exam->max_level_score ?? null,
            "total_score" => $this->total_score,
            "max_score" => $maxScore,
            "severity_band" => $this->severityBand->label ?? null,
            "severity_band_ar" => $this->severityBand->label_ar ?? $this->severityBand->label ?? null,
            "description" => $this->scoreDescription(),
            "is_alert_worthy" => (bool)($this->severityBand->is_alert_worthy ?? false),
            "computed_at" => $this->computed_at,
            "subscales" => $this->attempt->subscaleResults->map(function($res) {
                return [
                    "subscale_name" => $res->subscale->name,
                    "subscale_name_ar" => $res->subscale->name_ar ?? $res->subscale->name ?? null,
                    "score" => $res->total_score,
                    "band" => $res->severityBand->label ?? null,
                    "band_ar" => $res->severityBand->label_ar ?? $res->severityBand->label ?? null,
                    "is_alert_worthy" => (bool)($res->severityBand->is_alert_worthy ?? false)
                ];
            })
        ];
    }

    private function scoreDescription(): string {
        $label = $this->severityBand->label ?? null;
        $labelAr = $this->severityBand->label_ar ?? null;
        $joined = strtolower(($label ?? "") . " " . ($labelAr ?? ""));
        $score = $this->total_score;

        if (str_contains($joined, "severe") || str_contains($joined, "شديد") || str_contains($joined, "مرتفع")) {
            return "درجتك ($score) تعكس مستوى شدة مرتفعاً. سيراجع المختص نتائجك قريباً لتحديد خطة الدعم المناسبة.";
        }
        if (str_contains($joined, "moderate") || str_contains($joined, "متوسط") || str_contains($joined, "معتدل")) {
            return "درجتك ($score) تقع في النطاق المتوسط. يُنصح بمتابعة خطة العلاج والالتزام بالتمارين الموصوفة.";
        }
        if (str_contains($joined, "mild") || str_contains($joined, "خفيف")) {
            return "درجتك ($score) في النطاق الخفيف — استمر في تطبيق خطة العلاج الحالية.";
        }
        if (str_contains($joined, "normal") || str_contains($joined, "minimal") || str_contains($joined, "low") || str_contains($joined, "طبيعي") || str_contains($joined, "منخفض")) {
            return "درجتك ($score) ضمن النطاق الطبيعي. واصل اتباع خطة العلاج وتمارينك المنتظمة.";
        }
        return "تم احتساب درجتك ($score). سيطّلع عليها المختص لمتابعة حالتك.";
    }
}
