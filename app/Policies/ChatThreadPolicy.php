<?php
namespace App\Policies;
use App\Models\User;
use App\Models\ChatThread;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatThreadPolicy {
    use HandlesAuthorization;
    public function view(User $user, ChatThread $thread) {
        return $user->role === "admin" || $user->id === $thread->patient_id;
    }
    public function update(User $user, ChatThread $thread) {
        return $user->role === "admin" || $user->id === $thread->patient_id;
    }
}
