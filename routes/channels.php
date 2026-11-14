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
// Live sessions — قناة الأدمن. ملاحظة مهمة: بادئة `private-` تُجرَّد قبل مطابقة
// الكولباك (PusherBroadcaster::auth → normalizeChannelName)، لذا يجب التسجيل
// بلا بادئة — التسجيل القديم "private-live.{sessionId}.admin" لم يكن يُطابق أبدًا
// وكان سيفشل مصادقة القناة 403 فور تفعيل Reverb.
Broadcast::channel("live.{sessionId}.admin", function ($user, $sessionId) {
    $session = \App\Models\LiveSession::find($sessionId);
    return $session && (int) $user->id === (int) $session->admin_id;
});

// Live sessions — قناة الجلسة المشتركة: أدمن الجلسة أو مشارك منضم ولم يغادر بعد.
Broadcast::channel("live.{sessionId}", function ($user, $sessionId) {
    $session = \App\Models\LiveSession::find($sessionId);
    if (!$session) return false;
    if ((int) $user->id === (int) $session->admin_id) return true;
    return \App\Models\LiveParticipant::where("live_session_id", $session->id)
        ->where("user_id", $user->id)
        ->whereNull("left_at")
        ->exists();
});