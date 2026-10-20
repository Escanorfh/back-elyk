<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('fee_currency');
            $table->boolean('requires_admin_approval')->default(true)->after('is_public');
            $table->text('cancellation_reason')->nullable()->after('requires_admin_approval');
        });

        Schema::table('live_session_patients', function (Blueprint $table) {
            $table->enum('approval_status', ['pending_approval', 'approved', 'rejected'])
                ->default('pending_approval')
                ->after('patient_id');
            $table->foreignId('approved_by')->nullable()->after('approval_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_session_patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'rejection_reason']);
        });

        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn(['is_public', 'requires_admin_approval', 'cancellation_reason']);
        });
    }
};
