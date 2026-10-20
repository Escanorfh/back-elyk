<?php
namespace App\Http\Controllers;
use App\Models\LiveSession;
use App\Models\LiveParticipant;
use Illuminate\Http\Request;

class LiveKitWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // For production, you must verify the LiveKit signature via `webhook_receiver` in SDK.
        // For simplicity, we just parse the event here.
        
        // $receiver = new \Livekit\WebhookReceiver(config("services.livekit.api_key"), config("services.livekit.api_secret"));
        // $event = $receiver->receive($request->getContent(), $request->header("Authorization"));
        
        $event = json_decode($request->getContent(), true);
        if (!$event) return response()->json(["status" => "ok"]);

        $eventName = $event["event"] ?? null;
        $roomName = $event["room"]["name"] ?? null;
        $identity = $event["participant"]["identity"] ?? null;

        if (!$eventName || !$roomName) return response()->json(["status" => "ok"]);

        $session = LiveSession::where("livekit_room_name", $roomName)->first();
        if (!$session) return response()->json(["status" => "ok"]);

        if ($eventName === "participant_joined" && $identity) {
            LiveParticipant::where("live_session_id", $session->id)->where("livekit_identity", $identity)->update(["status" => "connected", "left_at" => null]);
        } elseif ($eventName === "participant_left" && $identity) {
            LiveParticipant::where("live_session_id", $session->id)->where("livekit_identity", $identity)->update(["status" => "left", "left_at" => now()]);
        } elseif ($eventName === "room_finished") {
            $session->update(["status" => "ended", "ended_at" => now()]);
        }

        return response()->json(["status" => "ok"]);
    }
}
