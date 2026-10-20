<?php
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
