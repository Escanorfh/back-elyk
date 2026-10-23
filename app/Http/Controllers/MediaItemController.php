<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\MediaItem;
use App\Models\Disorder;

class MediaItemController extends Controller {
    public function index(Request $request, Disorder $disorder) {
        $query = MediaItem::where("disorder_id", $disorder->id);

        if (!$request->boolean("include_inactive")) {
            $query->where("is_active", true);
        }
        if ($request->filled("stage")) {
            $query->where("stage", $request->integer("stage"));
        }

        $items = $query->get();
        return response()->json(["data" => $items]);
    }
    
    public function show(MediaItem $media_item) {
        return response()->json(["data" => $media_item]);
    }

    public function store(Request $request, Disorder $disorder) {
        $validated = $request->validate([
            "type" => "required|in:audio,pdf",
            "stage" => "nullable|integer|min:1|max:3",
            "title" => "required|string",
            "title_ar" => "nullable|string",
            "description" => "nullable|string",
            "file" => "required|file|max:20480",
            "duration_seconds" => "nullable|integer"
        ]);

        $file = $request->file("file");
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        // ملاحظة: نتحقق من الامتداد الأصلي للملف باسمه (وليس من نوع المحتوى فقط)،
        // لأن ملفات m4a تُصنّف أحياناً كـ video/mp4 (نفس حاوية MP4) فكانت تُرفض.
        if ($validated["type"] === "pdf") {
            $allowedExts = ["pdf"];
            $mediaLike = $mime === "application/pdf";
        } else {
            $allowedExts = ["mp3", "m4a", "wav", "aac", "ogg", "opus", "mpeg", "mp4"];
            $mediaLike = str_starts_with($mime, "audio/")
                || str_starts_with($mime, "video/")
                || $mime === "application/ogg";
        }

        if (!in_array($ext, $allowedExts, true) || !$mediaLike) {
            throw ValidationException::withMessages([
                "file" => ["صيغة الملف غير مدعومة. الصيغ المسموحة: ".implode(", ", $allowedExts)],
            ]);
        }

        // نحتفظ بامتداد الاسم الأصلي حتى يتعرّف التطبيق على نوع الوسيلة بشكل صحيح.
        $path = $file->storeAs("media", Str::random(40).".".$ext, "public");

        $item = MediaItem::create([
            "disorder_id" => $disorder->id,
            "type" => $validated["type"],
            "stage" => $validated["stage"] ?? null,
            "title" => $validated["title"],
            "title_ar" => $validated["title_ar"] ?? null,
            "description" => $validated["description"] ?? null,
            "file_path" => $path,
            "duration_seconds" => $validated["duration_seconds"] ?? null,
            "uploaded_by" => $request->user()->id,
            "is_active" => true
        ]);
        
        return response()->json(["data" => $item], 201);
    }

    public function update(Request $request, MediaItem $media_item) {
        $validated = $request->validate([
            "title" => "sometimes|string",
            "title_ar" => "nullable|string",
            "description" => "nullable|string",
            "stage" => "nullable|integer|min:1|max:3",
            "is_active" => "sometimes|boolean"
        ]);
        
        $media_item->update($validated);
        return response()->json(["data" => $media_item]);
    }
    
    public function destroy(MediaItem $media_item) {
        $inUse = \App\Models\PathStep::where("audio_media_id", $media_item->id)->orWhere("pdf_media_id", $media_item->id)->exists();
        if ($inUse) {
            abort(400, "Media item is in use by path steps. Please deactivate it instead.");
        }
        Storage::disk("public")->delete($media_item->file_path);
        $media_item->delete();
        return response()->json(["message" => "Deleted"]);
    }
}
