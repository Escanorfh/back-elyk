<?php
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
