<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class SubmitAnswerRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            "selected_option_ids" => "required|array",
            "selected_option_ids.*" => "integer|exists:question_options,id",
        ];
    }
}
