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
        Schema::table('intake_batteries', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->useCurrent()->after('status');
            $table->timestamp('last_reminded_at')->nullable()->after('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_batteries', function (Blueprint $table) {
            $table->dropColumn(['last_activity_at', 'last_reminded_at']);
        });
    }
};
