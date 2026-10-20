<?php
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
