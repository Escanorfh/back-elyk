<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
class ExamListResource extends JsonResource {
    public function toArray($request) {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "name_ar" => $this->name_ar,
            "disorder_name" => $this->disorder->name ?? null,
            "description" => $this->description,
        ];
    }
}
