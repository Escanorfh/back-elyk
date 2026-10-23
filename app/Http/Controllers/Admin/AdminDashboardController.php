<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamRetakeGrant;
use App\Models\LiveSession;
use App\Models\PatientPath;
use Illuminate\Http\Request;

/// جرام المرضى + إحصائيات لوحة تحكم المشرف.
class AdminDashboardController extends Controller {

    /// قايمة المرضى مع ملخص: حالة الفحص، المسار النشط، عدد التقييمات المكتملة.
    public function patientsIndex()
    {
        $patients = User::where("role", "patient")
            ->with([
                "patientPaths.disorder",
                "patientPaths.steps" => fn($q) => $q->withCount("assignment"),
            ])
            ->withCount(["examAttempts as completed_attempts" => fn($q) => $q->where("status", "completed")])
            ->orderBy("created_at", "desc")
            ->get()
            ->map(fn(User $u) => [
                "id" => $u->id,
                "name" => $u->name,
                "email" => $u->email,
                "role" => $u->role,
                "created_at" => $u->created_at,
                "intake_completed" => $u->intake_completed_at !== null,
                "completed_attempts" => $u->completed_attempts,
                "paths" => $u->patientPaths->map(fn(PatientPath $p) => [
                    "id" => $p->id,
                    "status" => $p->status,
                    "disorder" => [
                        "id" => $p->disorder?->id,
                        "name" => $p->disorder?->name,
                        "name_ar" => $p->disorder?->name_ar,
                    ],
                    "current_step_id" => $p->current_step_id,
                    "steps_count" => $p->steps->count(),
                ]),
            ]);

        return response()->json(["data" => $patients]);
    }

    /// إحصائيات لوحة التحكم: أعداد المرضى، المسارات، الجلسات، التقييمات.
    public function stats(Request $request)
    {
        return response()->json(["data" => [
            "patients_count" => User::where("role", "patient")->count(),
            "admins_count" => User::where("role", "admin")->count(),
            "active_paths" => PatientPath::where("status", "active")->count(),
            "completed_paths" => PatientPath::where("status", "completed")->count(),
            "total_exams_completed" => ExamAttempt::where("status", "completed")->count(),
            "unread_notifications" => $request->user()->unreadNotifications()->count(),
            "live_sessions" => [
                "scheduled" => LiveSession::where("status", "scheduled")->count(),
                "live" => LiveSession::where("status", "live")->count(),
                "ended" => LiveSession::where("status", "ended")->count(),
            ],
        ]]);
    }

    /// اختبارات/نتائج مريض معيّن (رؤية المشرف) — تعادل history المريض.
    public function patientAttempts(User $patient)
    {
        if ($patient->role !== "patient") abort(404);

        $history = $patient->examAttempts()
            ->where("status", "completed")
            ->with(["exam.disorder", "result.severityBand", "subscaleResults.subscale", "subscaleResults.severityBand"])
            ->orderBy("completed_at", "desc")
            ->get();

        return response()->json(["data" => $history]);
    }

    public function allowRetake(User $patient, Exam $exam)
    {
        if ($patient->role !== "patient") abort(404);

        ExamRetakeGrant::updateOrCreate(
            ["patient_id" => $patient->id, "exam_id" => $exam->id],
            ["granted_at" => now()]
        );

        return response()->json(["message" => "Exam retake allowed"]);
    }
}