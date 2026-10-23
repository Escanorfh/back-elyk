<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "role" => $this->role,
            "anonymous_label" => $this->anonymous_label,
            "status" => $this->status,
            "can_publish_audio" => (bool) $this->can_publish_audio,
        ];
    }
}
