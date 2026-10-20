<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * قالب المراحل للمرض: كل مرحلة فيها voice + report معاً.
     * بدل type المفرد (voice|pdf|assignment) نربط الوسائط مباشرة.
     */
    public function up(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->foreignId('audio_media_id')
                ->nullable()
                ->after('type')
                ->constrained('media_items')
                ->nullOnDelete();
            $table->foreignId('pdf_media_id')
                ->nullable()
                ->after('audio_media_id')
                ->constrained('media_items')
                ->nullOnDelete();
            $table->boolean('has_assignment')
                ->default(true)
                ->after('pdf_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audio_media_id');
            $table->dropConstrainedForeignId('pdf_media_id');
            $table->dropColumn('has_assignment');
        });
    }
};
