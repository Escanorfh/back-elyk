<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "user_id" => $this->user_id,
            "role" => $this->role,
            "anonymous_label" => $this->anonymous_label,
            "status" => $this->status,
            "can_publish_audio" => (bool) $this->can_publish_audio,
            "user" => [
                "id" => $this->user->id ?? null,
                "name" => $this->user->name ?? null,
                "email" => $this->user->email ?? null,
            ],
            "joined_at" => $this->joined_at,
            "left_at" => $this->left_at,
        ];
    }
}
