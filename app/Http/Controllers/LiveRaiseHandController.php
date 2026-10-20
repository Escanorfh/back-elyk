<?php
namespace App\Http\Controllers;
use App\Models\LiveSession;
use App\Models\LiveParticipant;
use App\Models\LiveRaiseHandRequest;
use App\Services\LiveKitService;
use App\Events\LiveRaiseHandEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LiveRaiseHandController extends Controller
{
    protected LiveKitService $liveKit;
    public function __construct(LiveKitService $liveKit) { $this->liveKit = $liveKit; }

    public function raiseHand(Request $request, LiveSession $session) {
        if ($session->status !== "live") abort(400, "Session is not live.");
        $participant = LiveParticipant::where("live_session_id", $session->id)->where("user_id", $request->user()->id)->firstOrFail();
        if ($participant->status === "speaking") abort(400, "Already speaking.");
        
        $req = LiveRaiseHandRequest::create([
            "live_session_id" => $session->id,
            "participant_id" => $participant->id,
            "status" => "pending"
        ]);
        $participant->update(["status" => "raised_hand"]);
        
        broadcast(new LiveRaiseHandEvent($req));
        return response()->json(["message" => "Hand raised"]);
    }

    public function queue(Request $request, LiveSession $session) {
        Gate::authorize("adminManage", $session);
        $requests = LiveRaiseHandRequest::with("participant.user")
            ->where("live_session_id", $session->id)
            ->where("status", "pending")->get();
        return response()->json(["data" => $requests]);
    }

    public function approve(Request $request, LiveRaiseHandRequest $handRequest) {
        $session = $handRequest->session;
        Gate::authorize("adminManage", $session);
        
        $handRequest->update(["status" => "approved", "resolved_at" => now(), "resolved_by" => $request->user()->id]);
        $participant = $handRequest->participant;
        $participant->update(["status" => "speaking", "can_publish_audio" => true]);
        
        $this->liveKit->updateParticipantPermissions($session->livekit_room_name, $participant->livekit_identity, true);
        return response()->json(["message" => "Approved"]);
    }

    public function deny(Request $request, LiveRaiseHandRequest $handRequest) {
        $session = $handRequest->session;
        Gate::authorize("adminManage", $session);
        
        $handRequest->update(["status" => "denied", "resolved_at" => now(), "resolved_by" => $request->user()->id]);
        $handRequest->participant->update(["status" => "connected"]);
        return response()->json(["message" => "Denied"]);
    }

    public function mute(Request $request, LiveSession $session, LiveParticipant $participant) {
        Gate::authorize("adminManage", $session);
        if ($participant->live_session_id !== $session->id) abort(404);
        
        $participant->update(["status" => "connected", "can_publish_audio" => false]);
        $this->liveKit->updateParticipantPermissions($session->livekit_room_name, $participant->livekit_identity, false);
        return response()->json(["message" => "Muted"]);
    }
}
