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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscale_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->text('text_ar');
            $table->enum('type', ['single_select', 'multi_select']);
            $table->integer('order')->default(0);            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
