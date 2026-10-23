<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\ChatThread;
use App\Models\ChatMessage;

class ChatSystemTest extends TestCase {
    use RefreshDatabase;

    public function test_patient_can_only_read_own_thread() {
        $patient1 = User::factory()->create(["role" => "patient"]);
        $patient2 = User::factory()->create(["role" => "patient"]);
        
        $thread1 = ChatThread::create(["patient_id" => $patient1->id]);
        $thread2 = ChatThread::create(["patient_id" => $patient2->id]);

        $response = $this->actingAs($patient1)->getJson("/api/v1/chat/threads/{$thread2->id}/messages");
        $response->assertStatus(403);

        $response2 = $this->actingAs($patient1)->getJson("/api/v1/chat/threads/{$thread1->id}/messages");
        $response2->assertStatus(200);
    }

    public function test_admin_can_read_any_thread() {
        $admin = User::factory()->create(["role" => "admin"]);
        $patient = User::factory()->create(["role" => "patient"]);
        $thread = ChatThread::create(["patient_id" => $patient->id]);

        $response = $this->actingAs($admin)->getJson("/api/v1/chat/threads/{$thread->id}/messages");
        $response->assertStatus(200);
    }
}
