<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disorder_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('order');          // stage number: 1, 2, 3...
            $table->string('title', 500);                  // stage title: "الجلسة الصوتية الأولى"
            $table->string('type', 50);                    // voice | pdf | assignment
            $table->text('description')->nullable();       // what this stage is about
            $table->timestamps();

            $table->unique(['disorder_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plans');
    }
};
