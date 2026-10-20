<?php
$base = __DIR__;

function getFilename($pattern) {
    global $base;
    $files = glob($base . '/database/migrations/*' . $pattern . '*.php');
    return $files[0] ?? null;
}

function writeMigration($pattern, $content) {
    $file = getFilename($pattern);
    if ($file) {
        file_put_contents($file, $content);
        echo "Updated: " . basename($file) . "\n";
    } else {
        echo "Could not find migration matching: $pattern\n";
    }
}

writeMigration('create_media_items_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("media_items", function (Blueprint $table) {
            $table->id();
            $table->foreignId("disorder_id")->constrained()->cascadeOnDelete();
            $table->enum("type", ["audio", "pdf"]);
            $table->string("title");
            $table->string("title_ar")->nullable();
            $table->text("description")->nullable();
            $table->string("file_path");
            $table->integer("duration_seconds")->nullable();
            $table->foreignId("uploaded_by")->constrained("users")->cascadeOnDelete();
            $table->boolean("is_active")->default(true);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("media_items");
    }
};
');

writeMigration('create_patient_paths_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("patient_paths", function (Blueprint $table) {
            $table->id();
            $table->foreignId("patient_id")->constrained("users")->cascadeOnDelete();
            $table->foreignId("disorder_id")->constrained()->cascadeOnDelete();
            $table->enum("status", ["draft", "active", "completed"])->default("draft");
            // current_step_id is added later or nullable
            $table->unsignedBigInteger("current_step_id")->nullable();
            $table->timestamps();
            
            $table->unique(["patient_id", "disorder_id"]);
        });
    }
    public function down(): void {
        Schema::dropIfExists("patient_paths");
    }
};
');

writeMigration('create_path_steps_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("path_steps", function (Blueprint $table) {
            $table->id();
            $table->foreignId("patient_path_id")->constrained("patient_paths")->cascadeOnDelete();
            $table->integer("order");
            $table->foreignId("audio_media_id")->nullable()->constrained("media_items")->nullOnDelete();
            $table->foreignId("pdf_media_id")->nullable()->constrained("media_items")->nullOnDelete();
            $table->enum("status", ["pending", "active", "submitted", "completed"])->default("pending");
            $table->timestamp("unlocked_at")->nullable();
            $table->timestamp("submitted_at")->nullable();
            $table->timestamp("completed_at")->nullable();
            $table->timestamps();
        });

        // Add foreign key for current_step_id
        Schema::table("patient_paths", function (Blueprint $table) {
            $table->foreign("current_step_id")->references("id")->on("path_steps")->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table("patient_paths", function (Blueprint $table) {
            $table->dropForeign(["current_step_id"]);
        });
        Schema::dropIfExists("path_steps");
    }
};
');

writeMigration('create_assignments_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("assignments", function (Blueprint $table) {
            $table->id();
            $table->foreignId("path_step_id")->unique()->constrained("path_steps")->cascadeOnDelete();
            $table->foreignId("patient_id")->constrained("users")->cascadeOnDelete();
            $table->text("text_response")->nullable();
            $table->string("file_path")->nullable();
            $table->timestamp("submitted_at")->useCurrent();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("assignments");
    }
};
');

writeMigration('create_assignment_feedback_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("assignment_feedback", function (Blueprint $table) {
            $table->id();
            $table->foreignId("assignment_id")->constrained("assignments")->cascadeOnDelete();
            $table->foreignId("admin_id")->constrained("users")->cascadeOnDelete();
            $table->text("note_text")->nullable();
            $table->string("file_path")->nullable();
            $table->boolean("is_visible_to_patient")->default(false);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("assignment_feedback");
    }
};
');

echo "Migrations populated.\n";
