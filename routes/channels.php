<?php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel("App.Models.User.{id}", function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel("chat.thread.{id}", function ($user, $id) {
    if ($user->role === "admin") return true;
    $thread = \App\Models\ChatThread::find($id);
    return $thread && (int) $user->id === (int) $thread->patient_id;
});

Broadcast::channel("admins", function ($user) {
    return $user->role === "admin";
});

// Patient wallet channel (wire: private-user.{id}) — payment + approval pushes.
Broadcast::channel("user.{id}", function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Admin approval-queue counter (wire: private-admin-approvals).
Broadcast::channel("admin-approvals", function ($user) {
    return $user->role === "admin";
});
Broadcast::channel("private-live.{sessionId}.admin", function ($user, $sessionId) {
    $session = \App\Models\LiveSession::find($sessionId);
    return $session && $user->id === $session->admin_id;
});