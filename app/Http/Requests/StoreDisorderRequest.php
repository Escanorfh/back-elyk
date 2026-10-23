<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreDisorderRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            "name" => "required|string|max:255",
            "name_ar" => "required|string|max:255",
            "description" => "nullable|string",
            // قالب المراحل الجاهز — يُجهَّز مرة واحدة عند إنشاء المرض
            // كل مرحلة: عنوان + voice + report معاً + تكليف اختياري
            "stages" => "sometimes|array",
            "stages.*.order" => "required_with:stages|integer|min:1",
            "stages.*.title" => "required_with:stages|string|max:500",
            "stages.*.description" => "nullable|string",
            "stages.*.audio_media_id" => "nullable|integer|exists:media_items,id",
            "stages.*.pdf_media_id" => "nullable|integer|exists:media_items,id",
            "stages.*.has_assignment" => "sometimes|boolean",
            "stages.*.type" => "nullable|string|in:voice,pdf,assignment",
        ];
    }
}
