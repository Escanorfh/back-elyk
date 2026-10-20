<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

/**
 * يقدّم ملفات الوسائط المخزّنة (storage/app/public) عبر PHP.
 *
 * السبب: خادم التطوير (php artisan serve) يقدّم /storage/* كملفات ثابتة
 * بدون ترويسة Access-Control-Allow-Origin وبدون دعم Range، فيفشل
 * تشغيل الصوت في المتصفح (Web Audio + crossOrigin=anonymous).
 */
class MediaStreamController extends Controller
{
    public function show(Request $request, string $path)
    {
        $relative = ltrim(str_replace("\\", "/", $path), "/");

        if ($relative === "" || str_contains($relative, "..")) {
            abort(400, "Invalid media path");
        }

        $root = realpath(storage_path("app/public"));
        if ($root === false) {
            abort(404, "Storage root not found");
        }

        $absolute = realpath($root . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative));
        if ($absolute === false || !is_file($absolute)) {
            abort(404, "Media not found");
        }

        if (!str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
            abort(403, "Forbidden media path");
        }

        $mime = $this->guessMime($absolute);

        return response()->file($absolute, [
            "Content-Type" => $mime,
            "Accept-Ranges" => "bytes",
            "Content-Disposition" => "inline",
            "Cache-Control" => "private, max-age=3600",
            "Access-Control-Allow-Origin" => "*",
            "Access-Control-Allow-Methods" => "GET, HEAD, OPTIONS",
            "Access-Control-Allow-Headers" => "Range, Content-Type, Authorization, X-Requested-With",
            "Access-Control-Expose-Headers" => "Content-Length, Content-Range, Accept-Ranges, Content-Type",
            "Access-Control-Max-Age" => "86400",
        ]);
    }

    private function guessMime(string $absolute): string
    {
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        $map = [
            "m4a" => "audio/mp4",
            "mp4" => "video/mp4",
            "aac" => "audio/aac",
            "mp3" => "audio/mpeg",
            "wav" => "audio/wav",
            "ogg" => "audio/ogg",
            "opus" => "audio/ogg",
            "webm" => "audio/webm",
            "flac" => "audio/flac",
            "amr" => "audio/amr",
            "pdf" => "application/pdf",
            "jpg" => "image/jpeg",
            "jpeg" => "image/jpeg",
            "png" => "image/png",
            "gif" => "image/gif",
            "webp" => "image/webp",
        ];

        return $map[$ext] ?? "application/octet-stream";
    }
}
