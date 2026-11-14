<?php
namespace App\Http\Controllers;
use App\Enums\SessionStatus;
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
        if ((string) $session->status !== SessionStatus::Live->value) abort(400, "Session is not live.");
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
        
        // موافقة DB لا تعني نجاح تحديث LiveKit — النتيجة تُفصل وتُبثّ وتُعاد للمتصرف.
        $result = $this->liveKit->updateParticipantPermissions($session->livekit_room_name, $participant->livekit_identity, true);
        $livekitUpdated = is_array($result) ? (bool) ($result["ok"] ?? false) : false;
        
        broadcast(new \App\Events\LiveParticipantPermissionEvent($session, $participant, true, $livekitUpdated));
        broadcast(new \App\Events\LiveHandResolvedEvent($handRequest->fresh(), "approved", $livekitUpdated));
        
        return response()->json([
            "message" => $livekitUpdated
                ? "Approved"
                : "Approved, but LiveKit permission update failed. Retry with microphone open to re-sync.",
            "livekit_updated" => $livekitUpdated,
            "data" => $participant->fresh(),
        ]);
    }

    public function deny(Request $request, LiveRaiseHandRequest $handRequest) {
        $session = $handRequest->session;
        Gate::authorize("adminManage", $session);
        
        $handRequest->update(["status" => "denied", "resolved_at" => now(), "resolved_by" => $request->user()->id]);
        $handRequest->participant->update(["status" => "connected"]);
        
        // الرفض لا يمس صلاحية LiveKit (كانت false أصلًا) — livekit_updated = null.
        broadcast(new \App\Events\LiveHandResolvedEvent($handRequest->fresh(), "denied", null));
        
        return response()->json(["message" => "Denied"]);
    }

    public function mute(Request $request, LiveSession $session, LiveParticipant $participant) {
        Gate::authorize("adminManage", $session);
        if ($participant->live_session_id !== $session->id) abort(404);
        
        $participant->update(["status" => "connected", "can_publish_audio" => false]);
        
        $result = $this->liveKit->updateParticipantPermissions($session->livekit_room_name, $participant->livekit_identity, false);
        $livekitUpdated = is_array($result) ? (bool) ($result["ok"] ?? false) : false;
        
        broadcast(new \App\Events\LiveParticipantPermissionEvent($session, $participant, false, $livekitUpdated));
        
        return response()->json([
            "message" => $livekitUpdated ? "Muted" : "Muted, but LiveKit permission update failed. Publishing will be re-revoked on retry.",
            "livekit_updated" => $livekitUpdated,
            "data" => $participant->fresh(),
        ]);
    }
}
