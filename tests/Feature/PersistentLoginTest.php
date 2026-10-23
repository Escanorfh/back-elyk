<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PersistentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_token_slides_forward()
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        // Simulate making a request
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/user');
        
        $response->assertStatus(200);

        // Verify last used at updated
        $accessToken = PersonalAccessToken::findToken($token);
        $this->assertNotNull($accessToken->last_used_at);
    }

    public function test_expired_token_is_rejected()
    {
        $user = User::factory()->create();
        $tokenObj = $user->createToken('auth_token');
        
        // Manually expire the token and clear created_at just to be safe
        $tokenObj->accessToken->forceFill([
            'last_used_at' => now()->subDays(65),
            'created_at' => now()->subDays(65),
        ])->save();

        // Request should fail due to Sanctum sliding expiry logic
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenObj->plainTextToken])
            ->postJson('/api/v1/auth/logout');
            
        $response->assertStatus(401);
    }
}
