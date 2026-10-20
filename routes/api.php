<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\DisorderController;
use App\Http\Controllers\Admin\ExamBuilderController;
use App\Http\Controllers\Patient\ExamController as PatientExamController;
use App\Http\Controllers\Patient\AttemptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// بثّ ملفات الوسائط مع ترويسات CORS ودعم Range.
// السبب: /storage/* يُقدَّم كملفات ثابتة بدون Access-Control-Allow-Origin،
// فيفشل تشغيل الصوت في المتصفح لأن Web Audio يطلب المصدر بـ crossOrigin=anonymous.
Route::get("/media-file/{path}", [\App\Http\Controllers\MediaStreamController::class, "show"])
    ->where("path", ".*");

Route::options("/media-file/{path}", function () {
    return response("", 204, [
        "Access-Control-Allow-Origin" => "*",
        "Access-Control-Allow-Methods" => "GET, HEAD, OPTIONS",
        "Access-Control-Allow-Headers" => "Range, Content-Type, Authorization, X-Requested-With",
        "Access-Control-Max-Age" => "86400",
    ]);
})->where("path", ".*");

Route::prefix('v1')->group(function () {

    // ─── Auth (public) ───
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // ─── Webhooks ───
    Route::post("/webhooks/livekit", [\App\Http\Controllers\LiveKitWebhookController::class, "handle"]);
    Route::post("/webhooks/fawry", [\App\Http\Controllers\Webhooks\FawryWebhookController::class, "handle"])
        ->middleware("webhook.signature:fawry");
    Route::post("/webhooks/instapay", [\App\Http\Controllers\Webhooks\InstaPayWebhookController::class, "handle"])
        ->middleware("webhook.signature:instapay");

    // ─── App Info (public) ───
    Route::get('/app/about', [\App\Http\Controllers\ProfileController::class, 'about']);
    Route::get('/app/privacy', [\App\Http\Controllers\ProfileController::class, 'privacy']);
    Route::get('/app/terms', [\App\Http\Controllers\ProfileController::class, 'terms']);

    // ─── Protected Routes ───
    Route::middleware(['auth:sanctum'])->group(function () {

        // Reverb private-channel auth (Echo → /api/v1/broadcasting/auth).
        \Illuminate\Support\Facades\Broadcast::routes();

        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

        // Profile
        Route::get('/user', [\App\Http\Controllers\ProfileController::class, 'show']);
        Route::put('/user', [\App\Http\Controllers\ProfileController::class, 'update']);
        Route::delete('/user', [\App\Http\Controllers\ProfileController::class, 'destroy']);
        Route::post('/user/avatar', [\App\Http\Controllers\ProfileController::class, 'updateAvatar']);
        Route::post('/user/background', [\App\Http\Controllers\ProfileController::class, 'updateBackground']);
        Route::put('/user/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword']);

        // Profile Settings
        Route::get('/user/settings/notifications', [\App\Http\Controllers\ProfileController::class, 'getNotificationSettings']);
        Route::put('/user/settings/notifications', [\App\Http\Controllers\ProfileController::class, 'updateNotificationSettings']);
        Route::get('/user/settings/language', [\App\Http\Controllers\ProfileController::class, 'getLanguage']);
        Route::put('/user/settings/language', [\App\Http\Controllers\ProfileController::class, 'updateLanguage']);
        Route::get('/user/settings/theme', [\App\Http\Controllers\ProfileController::class, 'getTheme']);
        Route::put('/user/settings/theme', [\App\Http\Controllers\ProfileController::class, 'updateTheme']);

        // Rate App
        Route::post('/app/rate', [\App\Http\Controllers\ProfileController::class, 'rate']);

        // Chat & Notifications
        Route::get("/notifications", [\App\Http\Controllers\NotificationController::class, "index"]);
        Route::post("/notifications/{id}/read", [\App\Http\Controllers\NotificationController::class, "read"]);
        Route::post("/notifications/read-all", [\App\Http\Controllers\NotificationController::class, "readAll"]);

        Route::post("/fcm-tokens", [\App\Http\Controllers\FcmTokenController::class, "store"]);
        Route::delete("/fcm-tokens", [\App\Http\Controllers\FcmTokenController::class, "destroy"]);

        Route::get("/chat/threads", [\App\Http\Controllers\ChatController::class, "threads"]);
        Route::get("/chat/threads/{thread}/messages", [\App\Http\Controllers\ChatController::class, "messages"]);
        Route::post("/chat/messages", [\App\Http\Controllers\ChatController::class, "sendMessage"]);
        Route::post("/chat/threads/{thread}/read", [\App\Http\Controllers\ChatController::class, "read"]);

        // Treatment Path — Admin
        Route::apiResource("disorders.media-items", \App\Http\Controllers\MediaItemController::class)->shallow();

        Route::get("/patients/{patient}/paths", [\App\Http\Controllers\PatientPathController::class, "indexAdmin"]);
        Route::get("/patient-paths/{path}", [\App\Http\Controllers\PatientPathController::class, "showAdmin"]);
        Route::post("/patients/{patient}/paths/{disorder}/steps", [\App\Http\Controllers\PathStepController::class, "store"]);
        Route::put("/path-steps/{step}", [\App\Http\Controllers\PathStepController::class, "update"]);
        Route::delete("/path-steps/{step}", [\App\Http\Controllers\PathStepController::class, "destroy"]);
        Route::put("/patient-paths/{path}/reorder", [\App\Http\Controllers\PatientPathController::class, "reorder"]);
        Route::post("/patient-paths/{path}/release-next", [\App\Http\Controllers\PatientPathController::class, "releaseNext"]);
        Route::post("/patient-paths/{path}/schedule-live", [\App\Http\Controllers\PatientPathController::class, "scheduleLive"]);
        Route::post("/path-steps/{step}/reopen", [\App\Http\Controllers\PathStepController::class, "reopen"]);

        Route::get("/path-steps/{step}/assignment", [\App\Http\Controllers\AssignmentController::class, "showAdmin"]);
        Route::post("/assignments/{assignment}/feedback", [\App\Http\Controllers\FeedbackController::class, "store"]);
        Route::put("/assignment-feedback/{feedback}", [\App\Http\Controllers\FeedbackController::class, "update"]);

        // Admin Routes
        Route::middleware('role:admin')->group(function () {
            Route::apiResource('disorders', DisorderController::class);
            Route::apiResource('exams', ExamBuilderController::class);
            Route::get('/exams/{exam}/full', [ExamBuilderController::class, 'full']);

            // Treatment Plans
            Route::get('/disorders/{disorder}/plans', [\App\Http\Controllers\Admin\TreatmentPlanController::class, 'index']);
            Route::post('/disorders/{disorder}/plans', [\App\Http\Controllers\Admin\TreatmentPlanController::class, 'store']);
            Route::get('/plans/{plan}', [\App\Http\Controllers\Admin\TreatmentPlanController::class, 'show']);
            Route::put('/plans/{plan}', [\App\Http\Controllers\Admin\TreatmentPlanController::class, 'update']);
            Route::delete('/plans/{plan}', [\App\Http\Controllers\Admin\TreatmentPlanController::class, 'destroy']);
            Route::post('/disorders/{disorder}/assign', [\App\Http\Controllers\Admin\TreatmentPlanController::class, 'assign']);

            Route::get('/admin/patients', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'patientsIndex']);
            Route::get('/admin/patients/{patient}/attempts', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'patientAttempts']);
            Route::post('/admin/patients/{patient}/exams/{exam}/allow-retake', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'allowRetake']);
            Route::get('/admin/stats', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'stats']);

            // Session Pricing
            Route::get('/admin/pricing', [\App\Http\Controllers\Admin\PricingController::class, 'index']);
            Route::put('/admin/pricing', [\App\Http\Controllers\Admin\PricingController::class, 'update']);
            Route::put('/admin/live-sessions/{session}/pricing', [\App\Http\Controllers\Admin\PricingController::class, 'updateSession']);

            // Session Approvals (Gate 2)
            Route::get('/admin/sessions/approval-queue', [\App\Http\Controllers\Admin\SessionApprovalController::class, 'queue']);
            Route::post('/admin/sessions/{session}/patients/{patient}/approve', [\App\Http\Controllers\Admin\SessionApprovalController::class, 'approve']);
            Route::post('/admin/sessions/{session}/patients/{patient}/reject', [\App\Http\Controllers\Admin\SessionApprovalController::class, 'reject']);
        });

        // Treatment Path — Patient
        Route::get("/me/paths", [\App\Http\Controllers\PatientPathController::class, "myPaths"]);
        Route::get("/me/paths/{path}", [\App\Http\Controllers\PatientPathController::class, "myPathDetail"]);
        Route::get("/me/paths/{path}/current-step", [\App\Http\Controllers\PatientPathController::class, "myCurrentStep"]);
        Route::post("/me/paths/{path}/mark-voice-completed", [\App\Http\Controllers\PatientPathController::class, "markVoiceCompleted"]);
        Route::post("/me/paths/{path}/submit-step", [\App\Http\Controllers\PatientPathController::class, "submitStep"]);
        Route::post("/path-steps/{step}/assignment", [\App\Http\Controllers\AssignmentController::class, "submit"]);
        Route::get("/assignments/{assignment}/feedback", [\App\Http\Controllers\FeedbackController::class, "indexPatient"]);

        // Patient Routes
        Route::middleware('role:patient')->group(function () {
            Route::post('/intake/start', [\App\Http\Controllers\Patient\IntakeController::class, 'start']);
            Route::get('/intake/status', [\App\Http\Controllers\Patient\IntakeController::class, 'status']);
            Route::get('/patient/exams', [PatientExamController::class, 'index']);
            Route::get('/patient/exams/{exam}/take', [PatientExamController::class, 'take']);
            Route::post('/patient/exams/{exam}/attempts', [AttemptController::class, 'store']);
            Route::put('/patient/attempts/{attempt}/answers/{question}', [AttemptController::class, 'updateAnswer']);
            Route::post('/patient/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
            Route::get('/patient/attempts/{attempt}/result', [AttemptController::class, 'result']);
            Route::get('/patient/me/attempts', [AttemptController::class, 'history']);
        });

        // Live Sessions
        Route::get("/live-sessions", [\App\Http\Controllers\LiveSessionController::class, "index"]);
        Route::post("/live-sessions", [\App\Http\Controllers\LiveSessionController::class, "store"]);
        Route::delete("/live-sessions/{session}", [\App\Http\Controllers\LiveSessionController::class, "destroy"]);
        Route::post("/live-sessions/{session}/start", [\App\Http\Controllers\LiveSessionController::class, "start"]);
        Route::post("/live-sessions/{session}/end", [\App\Http\Controllers\LiveSessionController::class, "end"]);
        Route::post("/live-sessions/{session}/join-token", [\App\Http\Controllers\LiveSessionController::class, "joinToken"]);
        Route::get("/live-sessions/{session}/participants", [\App\Http\Controllers\LiveSessionController::class, "participants"]);
        Route::post("/live-sessions/{session}/participants/{participant}/audio", [\App\Http\Controllers\LiveSessionController::class, "setAudio"]);
        Route::post("/live-sessions/{session}/participants/{participant}/chat-ban", [\App\Http\Controllers\LiveSessionController::class, "banChat"]);
        Route::post("/live-sessions/{session}/pay", [\App\Http\Controllers\LiveSessionController::class, "pay"]);
        Route::post("/live-sessions/{session}/payments/{payment}/confirm", [\App\Http\Controllers\LiveSessionController::class, "confirmPayment"]);
        Route::post("/live-sessions/{session}/pay/initiate", [\App\Http\Controllers\PaymentController::class, "initiate"]);
        Route::get("/live-sessions/{session}/pay/status", [\App\Http\Controllers\PaymentController::class, "status"]);

        Route::post("/live-sessions/{session}/raise-hand", [\App\Http\Controllers\LiveRaiseHandController::class, "raiseHand"]);
        Route::get("/live-sessions/{session}/raise-hand-queue", [\App\Http\Controllers\LiveRaiseHandController::class, "queue"]);
        Route::post("/raise-hand-requests/{handRequest}/approve", [\App\Http\Controllers\LiveRaiseHandController::class, "approve"]);
        Route::post("/raise-hand-requests/{handRequest}/deny", [\App\Http\Controllers\LiveRaiseHandController::class, "deny"]);
        Route::post("/live-sessions/{session}/participants/{participant}/mute", [\App\Http\Controllers\LiveRaiseHandController::class, "mute"]);

        Route::post("/live-sessions/{session}/chat", [\App\Http\Controllers\LiveChatController::class, "send"]);
        Route::get("/live-sessions/{session}/chat", [\App\Http\Controllers\LiveChatController::class, "index"]);
        Route::post("/live-sessions/{session}/chat/{message}/pin", [\App\Http\Controllers\LiveChatController::class, "togglePin"]);
        Route::delete("/live-sessions/{session}/chat/{message}", [\App\Http\Controllers\LiveChatController::class, "destroy"]);

        // Dev Only
        if (app()->environment('local', 'development', 'testing')) {
            Route::post('/dev/trigger-intake-reminders', function () {
                \Illuminate\Support\Facades\Artisan::call('intake:remind-stalled');
                return response()->json(['message' => \Illuminate\Support\Facades\Artisan::output()]);
            });
        }
    });
});
