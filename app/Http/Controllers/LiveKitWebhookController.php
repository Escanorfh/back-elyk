<?php

namespace App\Http\Controllers;

use App\Models\LiveParticipant;
use App\Models\LiveSession;
use App\Services\LiveKitWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * استقبال webhooks الخاصة بـ LiveKit (دخول/مغادرة المشاركين، انتهاء الغرفة).
 *
 * الأمان: كل طلب لازم يكون موقّع بـ JWT من LiveKit (هيدر Authorization)
 * ومتحقّق منه عبر [LiveKitWebhookVerifier] — وإلا أي حد يقدر يبعت
 * طلب مزيف ينهي جلسة أو يغيّر حالة مشارك.
 */
class LiveKitWebhookController extends Controller
{
    public function __construct(protected LiveKitWebhookVerifier $verifier)
    {
    }

    public function handle(Request $request)
    {
        $rawBody = (string) $request->getContent();

        [$ok, $reason, $claims] = $this->verifier->verify(
            $rawBody,
            $request->header('Authorization'),
        );

        if (! $ok) {
            Log::warning('LiveKit webhook rejected.', [
                'reason' => $reason,
                'ip' => $request->ip(),
                'room' => $claims['sub'] ?? null,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            return response()->json(['status' => 'ok']);
        }

        $eventName = $event['event'] ?? null;
        $roomName = $event['room']['name'] ?? ($claims['sub'] ?? null);
        $identity = $event['participant']['identity'] ?? null;

        if (! $eventName || ! $roomName) {
            return response()->json(['status' => 'ok']);
        }

        $session = LiveSession::where('livekit_room_name', $roomName)->first();
        if (! $session) {
            Log::info('LiveKit webhook for unknown room.', ['room' => $roomName, 'event' => $eventName]);

            return response()->json(['status' => 'ok']);
        }

        $participant = $identity
            ? LiveParticipant::where('live_session_id', $session->id)
                ->where('livekit_identity', $identity)
                ->first()
            : null;

        if ($eventName === 'participant_joined' && $participant) {
            $participant->update(['status' => 'connected', 'left_at' => null, 'joined_at' => $participant->joined_at ?? now()]);
        } elseif ($eventName === 'participant_left' && $participant) {
            $participant->update(['status' => 'left', 'left_at' => now()]);
        } elseif ($eventName === 'room_finished') {
            // انتهاء الغرفة = انتهاء الجلسة (ما لم تكن منتهية أصلاً).
            if ($session->status !== 'ended') {
                $session->update(['status' => 'ended', 'ended_at' => now()]);
            }
            LiveParticipant::where('live_session_id', $session->id)
                ->where('status', 'connected')
                ->update(['status' => 'left', 'left_at' => now()]);
        } elseif ($eventName === 'track_published' || $eventName === 'track_unpublished') {
            Log::info('LiveKit track event.', [
                'room' => $roomName,
                'event' => $eventName,
                'track' => $event['track']['info']['name'] ?? null,
                'source' => $event['track']['source'] ?? null,
            ]);
        }

        Log::info('LiveKit webhook handled.', [
            'room' => $roomName,
            'event' => $eventName,
            'identity' => $identity,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
