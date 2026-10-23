<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Dompdf\Dompdf;

class GenerateProfileReport extends Command
{
    protected $signature = 'report:profile';
    protected $description = 'Generate profile_report.pdf documenting all backend profile changes';

    public function handle(): int
    {
        $html = $this->getHtml();

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $path = public_path('profile_report.pdf');
        file_put_contents($path, $dompdf->output());

        $this->info("Report generated: {$path}");
        return Command::SUCCESS;
    }

    private function getHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<style>
  @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap');
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Cairo', 'Arial', sans-serif; color: #1e293b; line-height: 1.7; padding: 40px; }
  .header { text-align: center; margin-bottom: 36px; border-bottom: 3px solid #3b82f6; padding-bottom: 20px; }
  .header h1 { font-size: 26px; color: #111827; font-weight: 800; margin-bottom: 6px; }
  .header .sub { color: #6b86aa; font-size: 13px; }
  .section { margin-bottom: 28px; page-break-inside: avoid; }
  .section-title { font-size: 17px; font-weight: 800; color: #3b82f6; border-right: 4px solid #3b82f6; padding-right: 12px; margin-bottom: 14px; }
  table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  th { background: #eff6ff; color: #111827; font-weight: 700; padding: 10px 12px; text-align: right; border: 1px solid #bfdbfe; }
  td { padding: 9px 12px; border: 1px solid #e2e8f0; vertical-align: top; }
  tr:nth-child(even) td { background: #f8fafc; }
  .method { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; color: #fff; }
  .get { background: #22c55e; }
  .put { background: #3b82f6; }
  .post { background: #f59e0b; }
  .delete { background: #ef4444; }
  .note { background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; font-size: 12px; margin-top: 10px; }
  .file-card { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 14px 18px; margin-bottom: 10px; }
  .file-card h4 { font-size: 13px; color: #0369a1; margin-bottom: 4px; }
  .file-card p { font-size: 11.5px; color: #475569; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
  .badge-new { background: #dcfce7; color: #16a34a; }
  .badge-mod { background: #dbeafe; color: #2563eb; }
  .badge-run { background: #fef3c7; color: #b45309; }
  .footer { text-align: center; color: #94a3b8; font-size: 11px; margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 14px; }
</style>
</head>
<body>

<div class="header">
  <h1>تقرير تعديلات الباك اند — صفحة البروفايل</h1>
  <div class="sub">AALYK Backend — Profile Features Report | 18 سبتمبر 2026</div>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">ملخص التعديلات</div>
  <table>
    <tr><th>البند</th><th>التفاصيل</th></tr>
    <tr><td>ملفات جديدة</td><td>3 ملفات (Migration + Model + Migration)</td></tr>
    <tr><td>ملفات معدلة</td><td>2 ملف (Controller + Routes + User Model)</td></tr>
    <tr><td>Endpoints جديدة</td><td>16 endpoint</td></tr>
    <tr><td>جداول جديدة</td><td>2 (user_settings, app_ratings)</td></tr>
    <tr><td>أعمدة جديدة بالـ users</td><td>2 (photo_url, background_url)</td></tr>
  </table>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">الملفات الجديدة</div>

  <div class="file-card">
    <h4>database/migrations/2026_09_18_000001_add_profile_fields_to_users_table.php</h4>
    <p>إضافة عمودي photo_url و background_url إلى جدول users</p>
  </div>

  <div class="file-card">
    <h4>database/migrations/2026_09_18_000002_create_user_settings_table.php</h4>
    <p>إنشاء جدول user_settings لإعدادات المستخدم (إشعارات، لغة، مظهر)</p>
  </div>

  <div class="file-card">
    <h4>database/migrations/2026_09_18_000003_create_app_ratings_table.php</h4>
    <p>إنشاء جدول app_ratings لتقييمات المستخدمين للتطبيق</p>
  </div>

  <div class="file-card">
    <h4>app/Models/UserSettings.php</h4>
    <p>نموذج الإعدادات الشخصية — relationship مع User</p>
  </div>

  <div class="file-card">
    <h4>app/Models/AppRating.php</h4>
    <p>نموذج تقييمات التطبيق — relationship مع User</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">الملفات المعدلة</div>

  <div class="file-card">
    <h4>app/Http/Controllers/ProfileController.php <span class="badge badge-mod">تعديل كامل</span></h4>
    <p>إعادة كتابة الكنترولر بالكامل ليشمل كل features البروفايل: عرض البيانات، تحديث الاسم، صورة البروفايل، صورة الخلفية، تغيير كلمة المرور، إعدادات الإشعارات، اللغة، المظهر، حول التطبيق، سياسة الخصوصية، شروط الاستخدام، تقييم التطبيق، حذف الحساب</p>
  </div>

  <div class="file-card">
    <h4>app/Models/User.php <span class="badge badge-mod">تعديل</span></h4>
    <p>إضافة photo_url و background_url إلى $fillable + relationship الـ settings</p>
  </div>

  <div class="file-card">
    <h4>routes/api.php <span class="badge badge-mod">تعديل</span></h4>
    <p>إضافة 16 route جديد + إعادة تنظيم الملف بالكامل</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">جدول user_settings</div>
  <table>
    <tr><th>العمود</th><th>النوع</th><th>الافتراضي</th><th>الوصف</th></tr>
    <tr><td>id</td><td>bigint (PK)</td><td>—</td><td>المعرف الفريد</td></tr>
    <tr><td>user_id</td><td>foreignId (unique)</td><td>—</td><td>المستخدم المرتبط — cascade delete</td></tr>
    <tr><td>email_notifications</td><td>boolean</td><td>true</td><td>إشعارات البريد الإلكتروني</td></tr>
    <tr><td>push_notifications</td><td>boolean</td><td>true</td><td>إشعارات التطبيق</td></tr>
    <tr><td>session_reminders</td><td>boolean</td><td>true</td><td>تذكير بالجلسات</td></tr>
    <tr><td>language</td><td>string(10)</td><td>'ar'</td><td>لغة التطبيق (ar / en)</td></tr>
    <tr><td>theme</td><td>string(20)</td><td>'light'</td><td>المظهر (light / dark / auto)</td></tr>
    <tr><td>created_at</td><td>timestamp</td><td>—</td><td>تاريخ الإنشاء</td></tr>
    <tr><td>updated_at</td><td>timestamp</td><td>—</td><td>تاريخ التعديل</td></tr>
  </table>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">جدول app_ratings</div>
  <table>
    <tr><th>العمود</th><th>النوع</th><th>الوصف</th></tr>
    <tr><td>id</td><td>bigint (PK)</td><td>المعرف الفريد</td></tr>
    <tr><td>user_id</td><td>foreignId</td><td>المستخدم — cascade delete</td></tr>
    <tr><td>rating</td><td>unsignedTinyInteger</td><td>التقييم (1–5)</td></tr>
    <tr><td>comment</td><td>text (nullable)</td><td>تعليق المستخدم</td></tr>
    <tr><td>created_at / updated_at</td><td>timestamp</td><td>التواريخ</td></tr>
  </table>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">أعمدة جديدة بجدول users</div>
  <table>
    <tr><th>العمود</th><th>النوع</th><th>الوصف</th></tr>
    <tr><td>photo_url</td><td>string (nullable)</td><td>مسار صورة البروفايل (storage/avatars/)</td></tr>
    <tr><td>background_url</td><td>string (nullable)</td><td>مسار صورة الخلفية (storage/backgrounds/)</td></tr>
  </table>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">جميع الـ API Endpoints الجديدة</div>
  <table>
    <tr><th>الطريقة</th><th>المسار</th><th>الوصف</th><th>التوكن</th></tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/user</td>
      <td>عرض بيانات المستخدم + الإعدادات + الإحصائيات</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method put">PUT</span></td>
      <td>/api/v1/user</td>
      <td>تحديث الاسم</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method delete">DELETE</span></td>
      <td>/api/v1/user</td>
      <td>حذف الحساب نهائياً + الملفات المرتبطة</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method post">POST</span></td>
      <td>/api/v1/user/avatar</td>
      <td>رفع صورة البروفايل (multipart — max 5MB)</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method post">POST</span></td>
      <td>/api/v1/user/background</td>
      <td>رفع صورة الخلفية (multipart — max 10MB)</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method put">PUT</span></td>
      <td>/api/v1/user/password</td>
      <td>تغيير كلمة المرور (current_password + password + password_confirmation)</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/user/settings/notifications</td>
      <td>جلب إعدادات الإشعارات</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method put">PUT</span></td>
      <td>/api/v1/user/settings/notifications</td>
      <td>تحديث إعدادات الإشعارات</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/user/settings/language</td>
      <td>جلب اللغة الحالية</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method put">PUT</span></td>
      <td>/api/v1/user/settings/language</td>
      <td>تحديث اللغة (ar / en)</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/user/settings/theme</td>
      <td>جلب المظهر الحالي</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method put">PUT</span></td>
      <td>/api/v1/user/settings/theme</td>
      <td>تحديث المظهر (light / dark / auto)</td>
      <td>نعم</td>
    </tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/app/about</td>
      <td>معلومات التطبيق الثابتة</td>
      <td>لا</td>
    </tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/app/privacy</td>
      <td>سياسة الخصوصية</td>
      <td>لا</td>
    </tr>
    <tr>
      <td><span class="method get">GET</span></td>
      <td>/api/v1/app/terms</td>
      <td>شروط الاستخدام</td>
      <td>لا</td>
    </tr>
    <tr>
      <td><span class="method post">POST</span></td>
      <td>/api/v1/app/rate</td>
      <td>تقييم التطبيق (rating 1–5 + comment)</td>
      <td>نعم</td>
    </tr>
  </table>
</div>

<!-- ═══════════════════════════════════════════ -->
<div class="section">
  <div class="section-title">ملاحظات التنفيذ</div>
  <div class="note">
    <strong>1. حذف الملفات القديمة:</strong> عند تحديث صورة البروفايل أو الخلفية، يتم حذف الصورة القديمة من Storage تلقائياً.<br><br>
    <strong>2. ensureSettings:</strong> دالة مساعدة تضمن وجود سجل إعدادات لكل مستخدم — تُنشئ سجلاً بقيم افتراضية إن لم يوجد.<br><br>
    <strong>3. التحقق من الصيغ:</strong> Avatar يدعم jpg, jpeg, png, gif, webp — Background يدعم jpg, jpeg, png, webp فقط (بدون gif).<br><br>
    <strong>4. حذف الحساب:</strong> CASCADE على user_settings يحذف الإعدادات تلقائياً. يتم حذف الملفات + إبطال التوكنات قبل الحذف.<br><br>
    <strong>5. المحتوى الثابت (About/Privacy/Terms):</strong> يُرجع JSON ثابت حالياً — يمكن لاحقاً نقله إلى database أو CMS.<br><br>
    <strong>6. التقييمات:</strong> التحقق من الصلاحية (1–5) يتم على مستوى التطبيق وليس database (تم حذف CHECK constraint لعدم توفره).
  </div>
</div>

<div class="footer">
  AALYK Backend Report — Generated on September 18, 2026 — Laravel 12 + PHP 8.3
</div>

</body>
</html>
HTML;
    }
}
