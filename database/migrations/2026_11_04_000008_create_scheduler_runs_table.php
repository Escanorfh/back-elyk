<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * scheduler_runs: one row per external cron call, written before any work so a run that dies
 * mid-cycle is still visible.
 *
 * This table exists to answer "is the scheduler still alive?" — lag, backlog and lateness have to
 * be observable from inside the system, because the product must never claim a reminder was
 * guaranteed while cron is stopped. There is deliberately no created_at/updated_at pair: `run_at`
 * is that truth and the 7-day retention filter reads it, so a second column for the same fact
 * would be a second source of truth.
 */
return new class extends Migration
{
    private const TABLE = 'scheduler_runs';

    private const UNIQUE = 'scheduler_runs_batch_id_unique';

    private const INDEX = 'ix_runs_run_at';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('scheduler_runs already exists; this migration creates that table, so it refuses to run against one it did not create.');
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->dateTime('run_at');
            $table->string('batch_id', 36)->unique(self::UNIQUE);
            $table->unsignedInteger('due_considered')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('max_lateness_seconds')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->index('run_at', self::INDEX);
        });

        $names = (array) array_column(Schema::getIndexes(self::TABLE), 'name');
        $missing = array_values(array_diff([self::UNIQUE, self::INDEX], $names));

        if ($missing !== []) {
            throw new RuntimeException('scheduler_runs was created without its constraints: '.implode(', ', $missing).'.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
