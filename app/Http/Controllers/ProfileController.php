<?php

namespace App\Http\Controllers;

use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    // ──────────────── عرض البيانات ────────────────

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'user' => $user,
            'settings' => $this->ensureSettings($user),
        ];

        if ($user->role === 'patient') {
            $data['intake_completed'] = !is_null($user->intake_completed_at);
            $data['completed_assessments'] = $user->examAttempts()
                ->where('status', 'completed')->count();
            $data['active_paths'] = $user->patientPaths()->where('status', 'active')->count();
            $data['total_paths'] = $user->patientPaths()->count();
        }

        return response()->json(['data' => $data]);
    }

    // ──────────────── تحديث البيانات الشخصية ────────────────

    /**
     * PUT /user — تحديث الاسم.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $request->user()->update($validated);

        return response()->json([
            'data' => $request->user()->fresh(),
            'message' => 'تم تحديث البيانات بنجاح.',
        ]);
    }

    // ──────────────── صورة البروفايل ────────────────

    /**
     * POST /user/avatar — رفع صورة البروفايل.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'avatar' => 'required|file|image|max:5120',
        ]);

        $file = $request->file('avatar');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowedExts, true)) {
            throw ValidationException::withMessages([
                'avatar' => ['صيغة الملف غير مدعومة. الصيغ المسموحة: ' . implode(', ', $allowedExts)],
            ]);
        }

        $oldPath = $request->user()->photo_url;
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $file->storeAs(
            'avatars',
            $request->user()->id . '_' . Str::random(8) . '.' . $ext,
            'public'
        );

        $request->user()->update(['photo_url' => $path]);

        return response()->json([
            'data' => [
                'photo_url' => $path,
                'user' => $request->user()->fresh(),
            ],
            'message' => 'تم تحديث صورة البروفايل بنجاح.',
        ]);
    }

    // ──────────────── صورة الخلفية ────────────────

    /**
     * POST /user/background — رفع صورة الخلفية.
     */
    public function updateBackground(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'background' => 'required|file|image|max:10240',
        ]);

        $file = $request->file('background');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowedExts, true)) {
            throw ValidationException::withMessages([
                'background' => ['صيغة الملف غير مدعومة. الصيغ المسموحة: ' . implode(', ', $allowedExts)],
            ]);
        }

        $oldPath = $request->user()->background_url;
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $file->storeAs(
            'backgrounds',
            $request->user()->id . '_' . Str::random(8) . '.' . $ext,
            'public'
        );

        $request->user()->update(['background_url' => $path]);

        return response()->json([
            'data' => [
                'background_url' => $path,
                'user' => $request->user()->fresh(),
            ],
            'message' => 'تم تحديث صورة الخلفية بنجاح.',
        ]);
    }

    // ──────────────── تغيير كلمة المرور ────────────────

    /**
     * PUT /user/password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['كلمة المرور الحالية غير صحيحة.'],
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح.']);
    }

    // ──────────────── إعدادات الإشعارات ────────────────

    /**
     * GET /user/settings/notifications.
     */
    public function getNotificationSettings(Request $request): JsonResponse
    {
        $s = $this->ensureSettings($request->user());

        return response()->json([
            'data' => [
                'email_notifications' => $s->email_notifications,
                'push_notifications' => $s->push_notifications,
                'session_reminders' => $s->session_reminders,
            ],
        ]);
    }

    /**
     * PUT /user/settings/notifications.
     */
    public function updateNotificationSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_notifications' => 'sometimes|boolean',
            'push_notifications' => 'sometimes|boolean',
            'session_reminders' => 'sometimes|boolean',
        ]);

        $s = $this->ensureSettings($request->user());
        $s->update($validated);

        return response()->json([
            'data' => $s->fresh(),
            'message' => 'تم تحديث إعدادات الإشعارات.',
        ]);
    }

    // ──────────────── اللغة ────────────────

    /**
     * GET /user/settings/language.
     */
    public function getLanguage(Request $request): JsonResponse
    {
        $s = $this->ensureSettings($request->user());

        return response()->json(['data' => ['language' => $s->language]]);
    }

    /**
     * PUT /user/settings/language.
     */
    public function updateLanguage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'language' => 'required|string|in:ar,en',
        ]);

        $s = $this->ensureSettings($request->user());
        $s->update(['language' => $validated['language']]);

        return response()->json([
            'data' => ['language' => $s->fresh()->language],
            'message' => 'تم تحديث اللغة.',
        ]);
    }

    // ──────────────── المظهر ────────────────

    /**
     * GET /user/settings/theme.
     */
    public function getTheme(Request $request): JsonResponse
    {
        $s = $this->ensureSettings($request->user());

        return response()->json(['data' => ['theme' => $s->theme]]);
    }

    /**
     * PUT /user/settings/theme.
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => 'required|string|in:light,dark,auto',
        ]);

        $s = $this->ensureSettings($request->user());
        $s->update(['theme' => $validated['theme']]);

        return response()->json([
            'data' => ['theme' => $s->fresh()->theme],
            'message' => 'تم تحديث المظهر.',
        ]);
    }

    // ──────────────── حول التطبيق ────────────────

    /**
     * GET /app/about — معلومات التطبيق الثابتة.
     */
    public function about(): JsonResponse
    {
        return response()->json([
            'data' => [
                'app_name' => 'إليك — AALYK',
                'version' => '1.02-alfa',
                'content' => 'منصة صحية نفسية رقمية تقدم رعاية نفسية مدعومة بالذكاء الاصطناعي بأسلوب لطيف ومحترم.',
                'developer' => 'AALYK Team',
                'support_email' => 'support@aalyk.com',
                'website' => 'https://aalyk.com',
            ],
        ]);
    }

    // ──────────────── سياسة الخصوصية ────────────────

    /**
     * GET /app/privacy — نص سياسة الخصوصية.
     */
    public function privacy(): JsonResponse
    {
        return response()->json([
            'data' => [
                'title' => 'سياسة الخصوصية',
                'updated_at' => '2026-09-01',
                'content' => <<<'EOF'
                    سياسة الخصوصية — إليك (AALYK)
                    آخر تحديث: 1 سبتمبر 2026

                    1. جمع المعلومات
                    نقوم بجمع المعلومات التي تقدمها مباشرة عند التسجيل، بما في ذلك الاسم والبريد الإلكتروني. كما نجمع بيانات الاستخدام غير التعريفية لتحسين تجربتك.

                    2. استخدام المعلومات
                    تُستخدم بياناتك فقط لتوفير وتحسين خدماتنا. لن نبيع معلوماتك أبداً لأطراف ثالثة.

                    3. حماية البيانات
                    نستخدم تشفير SSL وبروتوكولات أمان متقدمة لحماية بياناتك. كلمات المرور مُشفّرة ولا يمكن الوصول إليها حتى من قبلنا.

                    4. تخزين البيانات
                    تُخزّن بياناتك على خوادم آمنة داخلبيئة محمية. نحتفظ ببياناتك طالما حسابك نشط.

                    5. حقوقك
                    لك الحق في الوصول إلى بياناتك وتصحيحها أو حذفها في أي وقت من خلال إعدادات حسابك.

                    6. التحفظ
                    إذا كان عمرك أقل من 18 عاماً، يرجى الحصول على إذن من ولي الأمر قبل استخدام التطبيق.
                EOF,
            ],
        ]);
    }

    // ──────────────── شروط الاستخدام ────────────────

    /**
     * GET /app/terms — نص شروط الاستخدام.
     */
    public function terms(): JsonResponse
    {
        return response()->json([
            'data' => [
                'title' => 'شروط الاستخدام',
                'updated_at' => '2026-09-01',
                'content' => <<<'EOF'
                    شروط الاستخدام — إليك (AALYK)
                    آخر تحديث: 1 سبتمبر 2026

                    1. القبول
                    باستخدامك لتطبيق إليك، أنت توافق على هذه الشروط بالكامل.

                    2. وصف الخدمة
                    إليك منصة رقمية توفر دعماً نفسياً مدعوماً بالذكاء الاصطناعي. خدماتنا لا تُغني عن الاستشارة الطبية المتخصصة أو العلاج النفسي المهني.

                    3. المستخدمون
                    يجب أن يكون عمرك 18 عاماً أو أكثر، أو الحصول على إذن ولي الأمر إذا كنت قاصراً.

                    4. المسؤوليات
                    أنت مسؤول عن الحفاظ على سرية بيانات حسابك. أنت مسؤول عن جميع الأنشطة التي تحدث تحت حسابك.

                    5. الاستخدام المحظور
                    يُحظر استخدام التطبيق لأي غرض غير قانوني أو للإيذاء أو للتنمر أو لل_content الضار.

                    6. الإخلال بالمسؤولية
                    خدماتنا مقدمة \"كما هي\". لا نضمن الدقة الكاملة لمحتوى الذكاء الاصطناعي. في حالات الطوارئ، اتصل برقم الطوارئ في بلدك.

                    7. تعديل الشروط
                    نحتفظ بحق تعديل هذه الشروط في أي وقت. سيتم إشعارك بالتغييرات الجوهرية.
                EOF,
            ],
        ]);
    }

    // ──────────────── تقييم التطبيق ────────────────

    /**
     * POST /app/rate — تسجيل تقييم المستخدم للتطبيق.
     */
    public function rate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        \App\Models\AppRating::create([
            'user_id' => $request->user()->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json(['message' => 'شكراً لتقييمك!']);
    }

    // ──────────────── حذف الحساب ────────────────

    /**
     * DELETE /user — حذف الحساب نهائياً.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->photo_url && Storage::disk('public')->exists($user->photo_url)) {
            Storage::disk('public')->delete($user->photo_url);
        }
        if ($user->background_url && Storage::disk('public')->exists($user->background_url)) {
            Storage::disk('public')->delete($user->background_url);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'تم حذف الحساب بنجاح.']);
    }

    // ──────────────── Helpers ────────────────

    private function ensureSettings($user): UserSettings
    {
        return UserSettings::firstOrCreate(
            ['user_id' => $user->id],
            [
                'email_notifications' => true,
                'push_notifications' => true,
                'session_reminders' => true,
                'language' => 'ar',
                'theme' => 'light',
            ]
        );
    }
}
