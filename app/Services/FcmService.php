<?php
namespace App\Services;

use App\Models\User;
use App\Models\FcmToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService {
    
    /**
     * Send a push notification to a user's FCM tokens.
     */
    public function sendToUser(User $user, array $payload) {
        $tokens = $user->fcmTokens()->pluck('token')->toArray();
        if (empty($tokens)) {
            return;
        }

        // Mocked check: If FCM isn't configured, just log it.
        if (!config('services.fcm.project_id')) {
            Log::info("FCM not configured. Mocking push to user {$user->id}: ", $payload);
            return;
        }

        // In a real implementation, you would generate an OAuth2 access token
        // using the Google API Client here. For brevity, we assume an access token is fetched.
        $accessToken = $this->getAccessToken();
        
        $projectId = config('services.fcm.project_id');
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $token) {
            $response = Http::withToken($accessToken)->post($endpoint, [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $payload['title'] ?? '',
                        'body' => $payload['body'] ?? '',
                    ],
                    'data' => $payload['data'] ?? []
                ]
            ]);

            // Handle token invalidation
            if ($response->status() === 404 || $response->status() === 400) {
                // If token is not found or invalid format (UNREGISTERED)
                $error = $response->json('error.details.0.errorCode');
                if ($error === 'UNREGISTERED' || $error === 'INVALID_ARGUMENT') {
                    FcmToken::where('token', $token)->delete();
                }
            }
        }
    }

    protected function getAccessToken() {
        // Implementation of fetching an OAuth2 token using Google Auth library.
        // Return a dummy token for now.
        return "dummy_fcm_access_token";
    }
}
