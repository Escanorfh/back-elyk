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
        Schema::create('subscales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar');
            $table->integer('order')->default(0);            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscales');
    }
};
