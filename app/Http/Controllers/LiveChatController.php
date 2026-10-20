<?php
namespace App\Http\Controllers;
use App\Models\LiveSession;
use App\Models\LiveParticipant;
use App\Models\LiveChatMessage;
use App\Events\LiveChatMessageEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LiveChatController extends Controller
{
    public function send(Request $request, LiveSession $session) {
        Gate::authorize("create", [\App\Models\LiveChatMessage::class, $session]);
        $validated = $request->validate(["body" => "required|string"]);
        
        $participant = LiveParticipant::where("live_session_id", $session->id)->where("user_id", $request->user()->id)->firstOrFail();
        if ($participant->is_chat_banned) abort(403, "You are banned from chatting in this session.");
        
        $msg = LiveChatMessage::create([
            "live_session_id" => $session->id,
            "participant_id" => $participant->id,
            "body" => $validated["body"]
        ]);
        
        broadcast(new LiveChatMessageEvent($msg));
        return response()->json(["data" => clone $msg]); // Send back without relation to client
    }

    public function index(Request $request, LiveSession $session) {
        Gate::authorize("viewAny", [\App\Models\LiveChatMessage::class, $session]);
        $messages = LiveChatMessage::with("participant.user")->where("live_session_id", $session->id)->orderBy("created_at")->get();
        return response()->json(["data" => $messages]);
    }

    // تثبيت/إلغاء تثبيت تعليق (الأدمن — تعليقه أو أي تعليق).
    public function togglePin(Request $request, LiveSession $session, LiveChatMessage $message) {
        Gate::authorize("update", $session);
        if ($message->live_session_id !== $session->id) abort(404);
        if ($message->is_pinned) {
            $message->update(["is_pinned" => false]);
            return response()->json(["message" => "Unpinned", "data" => $message->fresh()]);
        }
        LiveChatMessage::where("live_session_id", $session->id)->update(["is_pinned" => false]);
        $message->update(["is_pinned" => true]);
        return response()->json(["message" => "Pinned", "data" => $message->fresh()]);
    }

    // حذف تعليق (ban — الأدمن).
    public function destroy(Request $request, LiveSession $session, LiveChatMessage $message) {
        Gate::authorize("update", $session);
        if ($message->live_session_id !== $session->id) abort(404);
        $message->delete();
        return response()->json(["message" => "Message deleted"]);
    }
}
