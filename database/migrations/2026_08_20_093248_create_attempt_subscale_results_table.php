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
        Schema::create('attempt_subscale_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('subscale_id')->constrained()->cascadeOnDelete();
            $table->integer('total_score');
            $table->foreignId('severity_band_id')->nullable()->constrained()->nullOnDelete();            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attempt_subscale_results');
    }
};
