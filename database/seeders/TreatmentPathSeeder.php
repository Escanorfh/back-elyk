<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Disorder;
use App\Models\MediaItem;
use App\Models\PatientPath;
use App\Models\PathStep;

class TreatmentPathSeeder extends Seeder {
    public function run(): void {
        // Find or create admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            ['name' => 'Admin', 'password' => bcrypt('password'), 'role' => 'admin']
        );

        // Find or create patient
        $patient = User::firstOrCreate(
            ['email' => 'patient@test.com'],
            ['name' => 'Patient', 'password' => bcrypt('password'), 'role' => 'patient']
        );

        // Find or create disorder
        $disorder = Disorder::firstOrCreate(
            ['id' => 1],
            ['name' => 'OCD', 'name_ar' => 'الوسواس القهري']
        );

        // Create dummy media item
        $media = MediaItem::firstOrCreate(
            ['id' => 1],
            [
                'disorder_id' => $disorder->id,
                'type' => 'audio',
                'title' => 'Session 1 Audio',
                'file_path' => 'dummy/audio.mp3',
                'uploaded_by' => $admin->id,
                'is_active' => true
            ]
        );

        // Create patient path
        $path = PatientPath::firstOrCreate(
            ['patient_id' => $patient->id, 'disorder_id' => $disorder->id],
            ['status' => 'active']
        );

        // Create active path step
        $step = PathStep::firstOrCreate(
            ['patient_path_id' => $path->id, 'order' => 1],
            [
                'audio_media_id' => $media->id,
                'status' => 'active',
                'unlocked_at' => now()
            ]
        );

        $path->update(['current_step_id' => $step->id]);
        
        echo "Treatment path seeded successfully!\n";
    }
}
