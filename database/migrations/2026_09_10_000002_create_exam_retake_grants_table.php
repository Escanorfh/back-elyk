<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("exam_retake_grants", function (Blueprint $table) {
            $table->id();
            $table->foreignId("patient_id")->constrained("users")->cascadeOnDelete();
            $table->foreignId("exam_id")->constrained("exams")->cascadeOnDelete();
            $table->timestamp("granted_at");
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("exam_retake_grants");
    }
};