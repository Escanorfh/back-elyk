<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreExamRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            "disorder_id" => "required|exists:disorders,id",
            "name" => "required|string|max:255",
            "name_ar" => "required|string|max:255",
            "description" => "nullable|string",
            "is_active" => "boolean",
            // عتبات الكشف: min => عنده المرض | max => إشعار أحمر فوري للأدمن
            "min_level_score" => "nullable|integer|min:0",
            "max_level_score" => "nullable|integer|min:0",
            "subscales" => "array|min:1",
            "subscales.*.name" => "required|string",
            "subscales.*.name_ar" => "required|string",
            "questions" => "array",
            "questions.*.subscale_index" => "required|integer",
            "questions.*.text" => "required|string",
            "questions.*.text_ar" => "required|string",
            "questions.*.type" => "required|in:single_select,multi_select",
            "questions.*.options" => "array|min:1",
            "questions.*.options.*.text" => "required|string",
            "questions.*.options.*.score" => "required|integer",
            "bands" => "array",
            "bands.*.min_score" => "required|integer",
            "bands.*.max_score" => "required|integer",
            "bands.*.label" => "required|string",
        ];
    }
}
