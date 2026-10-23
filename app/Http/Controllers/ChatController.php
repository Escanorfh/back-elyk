<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Events\ChatMessageSent;
use App\Notifications\NewChatMessageNotification;

class ChatController extends Controller {
    public function threads(Request $request) {
        if ($request->user()->role === "patient") {
            $threads = ChatThread::where("patient_id", $request->user()->id)->get();
        } else {
            $threads = ChatThread::with("patient")->orderBy("updated_at", "desc")->get();
        }
        return response()->json(["data" => $threads]);
    }
    
    public function messages(Request $request, ChatThread $thread) {
        if ($request->user()->cannot("view", $thread)) abort(403);
        $messages = $thread->messages()->with("sender")->orderBy("created_at", "desc")->paginate(20);
        return response()->json($messages);
    }

    public function sendMessage(Request $request) {
        $validated = $request->validate([
            "body" => "nullable|string",
            "patient_id" => "integer|exists:users,id",
            "file" => "nullable|file|max:10240"
        ]);

        $hasText = !empty(trim($validated["body"] ?? ""));
        if (!$hasText && !$request->hasFile("file")) {
            abort(400, "Provide text or a file.");
        }

        $filePath = null;
        $fileName = null;
        if ($request->hasFile("file")) {
            $filePath = $request->file("file")->store("chat", "public");
            $fileName = $request->file("file")->getClientOriginalName();
        }

        $user = $request->user();
        if ($user->role === "patient") {
            $thread = ChatThread::firstOrCreate(["patient_id" => $user->id]);
        } else {
            if (empty($validated["patient_id"])) abort(400, "patient_id required for admin");
            $thread = ChatThread::firstOrCreate(["patient_id" => $validated["patient_id"]]);
        }

        $message = $thread->messages()->create([
            "sender_id" => $user->id,
            "sender_role" => $user->role,
            "body" => $hasText ? $validated["body"] : "",
            "file_path" => $filePath,
            "file_name" => $fileName
        ]);
        
        $thread->touch();

        // Broadcast to private channel
        broadcast(new ChatMessageSent($message))->toOthers();

        // Notify the OTHER party
        if ($user->role === "patient") {
            // Admins generally don't get individual unread counts for all patients unless specifically assigned,
            // but we can send an alert if needed. For now, they monitor the thread list.
        } else {
            $thread->patient->notify(new NewChatMessageNotification($message));
        }

        return response()->json(["data" => $message]);
    }

    public function read(Request $request, ChatThread $thread) {
        if ($request->user()->cannot("update", $thread)) abort(403);
        
        // Mark messages as read that are NOT sent by the current user
        $thread->messages()->where("sender_id", "!=", $request->user()->id)->whereNull("read_at")->update(["read_at" => now()]);
        return response()->json(["message" => "Marked as read"]);
    }
}
