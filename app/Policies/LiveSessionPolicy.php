<?php
namespace App\Policies;
use App\Models\LiveSession;
use App\Models\User;
class LiveSessionPolicy
{
    public function viewAny(User $user) { return true; }
    public function view(User $user, LiveSession $liveSession) { return true; }
    public function create(User $user) { return $user->role === "admin"; }
    public function update(User $user, LiveSession $liveSession) { return $user->id === $liveSession->admin_id; }
    public function delete(User $user, LiveSession $liveSession) { return $user->id === $liveSession->admin_id; }
    public function adminManage(User $user, LiveSession $liveSession) { return $user->id === $liveSession->admin_id; }
}
