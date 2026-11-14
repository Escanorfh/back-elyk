<?php

namespace App\Services;

use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;

class LiveKitService
{
    protected RoomServiceClient $client;
    protected string $apiKey;
    protected string $apiSecret;

    public function __construct()
    {
        $url = config('services.livekit.url', 'http://localhost:7880');
        $this->apiKey = config('services.livekit.api_key', 'devkey');
        $this->apiSecret = config('services.livekit.api_secret', 'super_secret_key_that_is_at_least_32_bytes_long');

        $this->client = new RoomServiceClient($url, $this->apiKey, $this->apiSecret);
    }

    public function createRoom(string $roomName)
    {
        $createOptions = (new \Agence104\LiveKit\RoomCreateOptions())
            ->setName($roomName)
            ->setEmptyTimeout(10 * 60)
            ->setMaxParticipants(100);
        return $this->client->createRoom($createOptions);
    }

    public function deleteRoom(string $roomName)
    {
        // الـ SDK يتوقع اسم الغرفة نصاً (يبني الـ Request داخلياً).
        return $this->client->deleteRoom($roomName);
    }

    public function createAccessToken(string $roomName, string $identity, string $name, bool $canPublishAudio, bool $canPublishVideo)
    {
        $tokenOptions = (new AccessTokenOptions())
            ->setIdentity($identity)
            ->setName($name);

        $videoGrant = (new VideoGrant())
            ->setRoomJoin()
            ->setRoomName($roomName)
            ->setCanPublish($canPublishAudio || $canPublishVideo)
            ->setCanPublishData(true)
            ->setCanSubscribe(true)
            ->setCanPublishSources(['camera', 'microphone', 'screen_share', 'screen_share_audio']);

        return (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($tokenOptions)
            ->setGrant($videoGrant)
            ->toJwt();
    }

    /**
     * تحديث صلاحية الكلام لمشارك داخل غرفة LiveKit حيًا (بدون إعادة دخول).
     *
     * يعيد نتيجة منظمة بدل ابتلاع الفشل — عقد ثابت:
     * ['ok' => bool, 'code' => ?string, 'attempts' => int, 'can_publish_audio' => bool]
     * code: invalid_input | livekit_error (null عند النجاح)
     *
     * السجل آمن: أسماء الغرفة/الهوية/الصلاحية المطلوبة فقط — بلا tokens أو secrets.
     */
    public function updateParticipantPermissions(string $roomName, string $identity, bool $canPublishAudio): array
    {
        $roomName = trim($roomName);
        $identity = trim($identity);

        // تحقق من صحة المدخلات قبل أي نداء شبكة.
        if ($roomName === '' || $identity === '') {
            \Log::warning('LiveKit permission update skipped: invalid input.', [
                'room' => $roomName !== '' ? $roomName : null,
                'identity' => $identity !== '' ? $identity : null,
                'can_publish_audio' => $canPublishAudio,
            ]);
            return [
                'ok' => false,
                'code' => 'invalid_input',
                'attempts' => 0,
                'can_publish_audio' => $canPublishAudio,
            ];
        }

        $permission = new \Livekit\ParticipantPermission([
            'can_publish' => $canPublishAudio,
            'can_subscribe' => true,
            'can_publish_data' => true,
        ]);

        $attempts = 0;
        $lastError = null;

        // محاولة إعادة واحدة للفشل العابر (توقيت/إعادة اتصال). مشارك غير موجود
        // داخل الغرفة يظهر كخطأ من UpdateParticipant نفسه — ونكشفه في النتيجة.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $attempts = $attempt;
            try {
                $this->client->updateParticipant($roomName, $identity, null, $permission);
                return [
                    'ok' => true,
                    'code' => null,
                    'attempts' => $attempts,
                    'can_publish_audio' => $canPublishAudio,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt === 1) {
                    usleep(300_000); // مهلة قصيرة بين المحاولتين فقط.
                }
            }
        }

        \Log::error('LiveKit Permission Update Failed', [
            'room' => $roomName,
            'identity' => $identity,
            'can_publish_audio' => $canPublishAudio,
            'attempts' => $attempts,
            'error' => $lastError,
        ]);

        return [
            'ok' => false,
            'code' => 'livekit_error',
            'attempts' => $attempts,
            'can_publish_audio' => $canPublishAudio,
        ];
    }
}
