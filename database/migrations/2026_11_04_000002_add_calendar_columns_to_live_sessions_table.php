<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar columns on live_sessions: planned end, timezone, priority, lifecycle stamps and the
 * reschedule history. Additive only — every column is nullable or defaulted, and status widening
 * lives in migration 000001, which also owns the only index on this table.
 *
 * `rescheduled_at`/`previous_scheduled_at` exist because `rescheduled` must never become a status
 * value: patient lists filter status in (scheduled, live), so such a session would disappear from
 * the calendar instead of moving to its new time.
 */
return new class extends Migration
{
    private const TABLE = 'live_sessions';

    private const COLUMNS = [
        'ends_at', 'timezone', 'priority', 'description', 'location',
        'confirmed_at', 'cancelled_at', 'missed_at',
        'previous_scheduled_at', 'rescheduled_at', 'rescheduled_by',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('live_sessions does not exist; refusing to create it from a calendar migration.');
        }

        $present = array_values(array_filter(self::COLUMNS, fn (string $c): bool => Schema::hasColumn(self::TABLE, $c)));

        if ($present !== [] && count($present) !== count(self::COLUMNS)) {
            throw new RuntimeException(
                'live_sessions holds a partial calendar column set ('.implode(', ', $present).'). '
                .'Refusing to guess which of the remaining columns are safe to add.'
            );
        }

        if ($present === []) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dateTime('ends_at')->nullable();
                $table->string('timezone', 64)->default('UTC');
                $table->string('priority', 8)->default('normal');
                $table->text('description')->nullable();
                $table->string('location')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->dateTime('missed_at')->nullable();
                $table->dateTime('previous_scheduled_at')->nullable();
                $table->dateTime('rescheduled_at')->nullable();
                $table->foreignId('rescheduled_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        $this->assertApplied();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'rescheduled_by')) {
                $table->dropConstrainedForeignId('rescheduled_by');
            }
        });

        $remaining = array_values(array_filter(
            self::COLUMNS,
            fn (string $column): bool => $column !== 'rescheduled_by' && Schema::hasColumn(self::TABLE, $column)
        ));

        if ($remaining !== []) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($remaining));
        }
    }

    private function assertApplied(): void
    {
        $missing = array_values(array_filter(self::COLUMNS, fn (string $c): bool => ! Schema::hasColumn(self::TABLE, $c)));

        if ($missing !== []) {
            throw new RuntimeException('Calendar columns were not created on live_sessions: '.implode(', ', $missing).'.');
        }
    }
};
