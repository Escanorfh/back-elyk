<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('intake_batteries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intake_battery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_battery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->integer('order');
            $table->enum('status', ['pending', 'active', 'completed'])->default('pending');
            $table->foreignId('attempt_id')->nullable()->constrained('exam_attempts')->nullOnDelete();
            $table->timestamps();
            
            $table->unique(['intake_battery_id', 'exam_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_battery_items');
        Schema::dropIfExists('intake_batteries');
    }
};
