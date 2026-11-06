<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task columns on path_steps: the therapeutic homework a calendar item represents.
 *
 * `due_at` belongs here, not on `assignments`: an assignments row is only created when the patient
 * submits, so it cannot represent an assigned-but-unsubmitted task or an overdue state.
 * `assignments.submitted_at` stays the sole proof of submission and `path_steps.completed_at` the
 * sole proof of acceptance. `status` is untouched — its existing enum already covers every bucket,
 * and `overdue` is computed from (due_at, status, now), never stored.
 */
return new class extends Migration
{
    private const TABLE = 'path_steps';

    private const COLUMNS = ['due_at', 'due_timezone', 'priority', 'category', 'description', 'assigned_by'];

    private const INDEX = 'ix_steps_due_status';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('path_steps does not exist; refusing to create it from a calendar migration.');
        }

        $present = array_values(array_filter(self::COLUMNS, fn (string $c): bool => Schema::hasColumn(self::TABLE, $c)));

        if ($present !== [] && count($present) !== count(self::COLUMNS)) {
            throw new RuntimeException(
                'path_steps holds a partial task column set ('.implode(', ', $present).'). '
                .'Refusing to guess which of the remaining columns are safe to add.'
            );
        }

        if ($present === []) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dateTime('due_at')->nullable();
                $table->string('due_timezone', 64)->default('UTC');
                $table->string('priority', 8)->default('normal');
                $table->string('category', 32)->default('therapeutic_homework');
                $table->text('description')->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (! in_array(self::INDEX, $this->indexNames(), true)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->index(['due_at', 'status'], self::INDEX));
        }

        $missing = array_values(array_filter(self::COLUMNS, fn (string $c): bool => ! Schema::hasColumn(self::TABLE, $c)));

        if ($missing !== [] || ! in_array(self::INDEX, $this->indexNames(), true)) {
            throw new RuntimeException(
                'path_steps did not reach the expected shape: columns '.json_encode($missing)
                .', indexes '.json_encode($this->indexNames()).'.'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (in_array(self::INDEX, $this->indexNames(), true)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'assigned_by')) {
                $table->dropConstrainedForeignId('assigned_by');
            }
        });

        $remaining = array_values(array_filter(
            self::COLUMNS,
            fn (string $column): bool => $column !== 'assigned_by' && Schema::hasColumn(self::TABLE, $column)
        ));

        if ($remaining !== []) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($remaining));
        }
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_values(array_map(
            fn (array $index): string => (string) $index['name'],
            Schema::getIndexes(self::TABLE)
        ));
    }
};
