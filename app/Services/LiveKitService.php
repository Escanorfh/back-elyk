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
            ->setCanSubscribe(true);

        return (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($tokenOptions)
            ->setGrant($videoGrant)
            ->toJwt();
    }

    public function updateParticipantPermissions(string $roomName, string $identity, bool $canPublishAudio)
    {
        $permission = new \Livekit\ParticipantPermission([
            'can_publish' => $canPublishAudio,
            'can_subscribe' => true,
            'can_publish_data' => true,
        ]);
        
        try {
            return $this->client->updateParticipant($roomName, $identity, null, $permission);
        } catch (\Exception $e) {
            \Log::error("LiveKit Permission Update Failed: " . $e->getMessage());
        }
    }
}
