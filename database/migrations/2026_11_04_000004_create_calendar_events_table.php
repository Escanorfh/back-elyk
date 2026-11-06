<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calendar_events: personal and important-day entries — the only non-clinical events that are not
 * sessions. Sessions stay in live_sessions and homework in path_steps; the calendar is an
 * aggregation layer over them, so there is no calendar_items table and no duplicated row.
 *
 * `created_by` is NULL when the patient created the event themselves; editability is derived from
 * `created_by !== user_id` rather than stored as a second, drift-prone flag.
 */
return new class extends Migration
{
    private const TABLE = 'calendar_events';

    private const INDEX = 'ix_events_owner_start';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('calendar_events already exists; this migration creates that table, so it refuses to run against one it did not create.');
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('personal_reminder');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->default('UTC');
            $table->string('location')->nullable();
            $table->string('priority', 8)->default('normal');
            $table->string('status', 16)->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->index(['user_id', 'starts_at'], self::INDEX);
        });

        if (! in_array(self::INDEX, array_column(Schema::getIndexes(self::TABLE), 'name'), true)) {
            throw new RuntimeException(self::INDEX.' is absent after creating calendar_events.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
