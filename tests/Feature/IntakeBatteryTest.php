<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Option;
use App\Models\IntakeBattery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntakeBatteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_battery_creation_and_enforcement()
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $disorder = \App\Models\Disorder::create(['name' => 'Depression', 'name_ar' => 'اكتئاب']);
        $exam1 = Exam::create(['name' => 'Exam 1', 'name_ar' => '1', 'is_active' => true, 'disorder_id' => $disorder->id]);
        $exam2 = Exam::create(['name' => 'Exam 2', 'name_ar' => '2', 'is_active' => true, 'disorder_id' => $disorder->id]);

        // Start intake
        $response = $this->actingAs($patient)->postJson('/api/v1/intake/start');
        $response->assertStatus(200);

        // Fetch status
        $statusResp = $this->actingAs($patient)->getJson('/api/v1/intake/status');
        $statusResp->assertStatus(200);
        $activeExamId = $statusResp->json('data.current.exam.id');

        $otherExamId = $activeExamId === $exam1->id ? $exam2->id : $exam1->id;

        // Try taking the wrong exam
        $failResp = $this->actingAs($patient)->postJson("/api/v1/patient/exams/{$otherExamId}/attempts");
        $failResp->assertStatus(403);

        // Take the correct exam
        $successResp = $this->actingAs($patient)->postJson("/api/v1/patient/exams/{$activeExamId}/attempts");
        $successResp->assertStatus(200);
        $attemptId = $successResp->json('data.attempt_id');

        // Verify last activity was touched
        $battery = IntakeBattery::where('patient_id', $patient->id)->first();
        $this->assertNotNull($battery->last_activity_at);
    }
}
