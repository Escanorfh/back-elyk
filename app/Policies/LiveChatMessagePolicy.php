<?php
namespace App\Policies;
use App\Enums\SessionStatus;
use App\Models\LiveSession;
use App\Models\LiveChatMessage;
use App\Models\User;
class LiveChatMessagePolicy
{
    public function viewAny(User $user, LiveSession $liveSession) {
        if ($user->role === "admin") {
            return $user->id === $liveSession->admin_id;
        }
        return \App\Models\LiveParticipant::where("live_session_id", $liveSession->id)
            ->where("user_id", $user->id)
            ->exists();
    }
    public function create(User $user, LiveSession $liveSession) {
        // الشات داخل البثّ فقط: مؤكَّدة قبل الهواء ولا منتهية/ملغاة بعدها.
        return (string) $liveSession->status === SessionStatus::Live->value;
    }
}
