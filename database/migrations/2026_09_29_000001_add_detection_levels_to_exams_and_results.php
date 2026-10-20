<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * عتبات الكشف لكل فورم (exam):
     * - min_level_score: لو المريض عدّاه => عنده المرض ده (disorder_detected).
     * - max_level_score: لو المريض عدّاه => إشعار أحمر فوري للأدمن.
     * + عمود disorder_detected على attempt_results (الأثر المحسوب لكل نتيجة).
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->integer('min_level_score')->nullable()->after('description');
            $table->integer('max_level_score')->nullable()->after('min_level_score');
        });

        Schema::table('attempt_results', function (Blueprint $table) {
            $table->boolean('disorder_detected')->default(true)->after('disorder_id');
        });
    }

    public function down(): void
    {
        Schema::table('attempt_results', function (Blueprint $table) {
            $table->dropColumn('disorder_detected');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['min_level_score', 'max_level_score']);
        });
    }
};
