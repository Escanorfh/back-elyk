<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Disorder;
use App\Models\PatientPath;
use App\Models\PathStep;
use App\Models\Assignment;
use App\Models\AssignmentFeedback;
use App\Models\MediaItem;

class TreatmentPathTest extends TestCase {
    use RefreshDatabase;

    public function test_patient_can_only_access_own_path() {
        $patient1 = User::factory()->create(["role" => "patient"]);
        $patient2 = User::factory()->create(["role" => "patient"]);
        $disorder = Disorder::create(["name" => "D1", "name_ar" => "D1"]);
        
        $path1 = PatientPath::create(["patient_id" => $patient1->id, "disorder_id" => $disorder->id]);
        $step1 = PathStep::create(["patient_path_id" => $path1->id, "order" => 1, "status" => "active"]);
        
        // Patient 2 tries to access Patient 1's step
        $response = $this->actingAs($patient2)->postJson("/api/v1/path-steps/{$step1->id}/assignment", [
            "text_response" => "Hello"
        ]);
        $response->assertStatus(403);
        
        // Patient 1 tries to access Patient 1's step
        $response2 = $this->actingAs($patient1)->postJson("/api/v1/path-steps/{$step1->id}/assignment", [
            "text_response" => "Hello"
        ]);
        $response2->assertStatus(201);
    }

    public function test_admin_cannot_release_next_step_while_current_is_not_submitted() {
        $admin = User::factory()->create(["role" => "admin"]);
        $patient = User::factory()->create(["role" => "patient"]);
        $disorder = Disorder::create(["name" => "D1", "name_ar" => "D1"]);
        
        $path = PatientPath::create(["patient_id" => $patient->id, "disorder_id" => $disorder->id]);
        $step1 = PathStep::create(["patient_path_id" => $path->id, "order" => 1, "status" => "active"]);
        $path->update(["current_step_id" => $step1->id]);
        
        $response = $this->actingAs($admin)->postJson("/api/v1/patient-paths/{$path->id}/release-next");
        $response->assertStatus(400); // Because step1 is not submitted yet
        
        // Submit step1
        $step1->update(["status" => "submitted"]);
        $response2 = $this->actingAs($admin)->postJson("/api/v1/patient-paths/{$path->id}/release-next");
        $response2->assertStatus(200); // Success
    }

    public function test_patient_cannot_see_invisible_feedback() {
        $patient = User::factory()->create(["role" => "patient"]);
        $admin = User::factory()->create(["role" => "admin"]);
        $disorder = Disorder::create(["name" => "D1", "name_ar" => "D1"]);
        
        $path = PatientPath::create(["patient_id" => $patient->id, "disorder_id" => $disorder->id]);
        $step = PathStep::create(["patient_path_id" => $path->id, "order" => 1, "status" => "submitted"]);
        $path->update(["current_step_id" => $step->id]);
        
        $assignment = Assignment::create(["path_step_id" => $step->id, "patient_id" => $patient->id, "text_response" => "Test"]);
        
        $feedbackHidden = AssignmentFeedback::create(["assignment_id" => $assignment->id, "admin_id" => $admin->id, "note_text" => "Hidden", "is_visible_to_patient" => false]);
        $feedbackVisible = AssignmentFeedback::create(["assignment_id" => $assignment->id, "admin_id" => $admin->id, "note_text" => "Visible", "is_visible_to_patient" => true]);

        $response = $this->actingAs($patient)->getJson("/api/v1/me/paths/{$path->id}/current-step");
        $response->assertStatus(200);
        $data = $response->json("data");
        
        $this->assertCount(1, $data["assignment"]["feedback"]);
        $this->assertEquals("Visible", $data["assignment"]["feedback"][0]["note_text"]);

        // Test the standalone GET endpoint
        $response3 = $this->actingAs($patient)->getJson("/api/v1/assignments/{$assignment->id}/feedback");
        $response3->assertStatus(200);
        $data3 = $response3->json("data");
        $this->assertCount(1, $data3);
        $this->assertEquals("Visible", $data3[0]["note_text"]);
    }
}
