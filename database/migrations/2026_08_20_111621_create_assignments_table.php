<?php
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
