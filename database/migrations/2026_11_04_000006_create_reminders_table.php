<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reminders: one scheduled instant per (item, offset). Polymorphic, because a reminder can hang on
 * a session, a path step, a personal task or a calendar event — the same pattern the existing
 * notifications table uses.
 *
 * `reminds_at` is an absolute UTC instant computed at creation (base time minus offset), never
 * recomputed at display time; `timezone` is kept only as the audit trail of the original intent.
 * Rescheduling therefore cancels rows and inserts new ones instead of mutating history — which is
 * also why `reminds_at` is part of the unique identity: a new cycle must not collide with the
 * cancelled one. Claiming is `pending -> sending` per row with a lease (`claimed_at`) so a crashed
 * run cannot strand a reminder and two overlapping cron calls cannot both send.
 */
return new class extends Migration
{
    private const TABLE = 'reminders';

    private const UNIQUE = 'uq_reminders_cycle';

    private const INDEX = 'ix_reminders_claim';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('reminders already exists; this migration creates that table, so it refuses to run against one it did not create.');
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('audience', 16)->default('participants');
            $table->unsignedInteger('offset_minutes')->default(0);
            $table->dateTime('reminds_at');
            $table->string('timezone', 64)->default('UTC');
            $table->string('channel', 24)->default('auto');
            $table->string('status', 16)->default('pending');
            $table->dateTime('claimed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['notifiable_type', 'notifiable_id', 'offset_minutes', 'reminds_at'], self::UNIQUE);
            $table->index(['status', 'reminds_at'], self::INDEX);
        });

        $this->assertOwnObjects();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function assertOwnObjects(): void
    {
        $names = array_column(Schema::getIndexes(self::TABLE), 'name');

        $missing = array_values(array_diff([self::UNIQUE, self::INDEX], (array) $names));

        if ($missing !== []) {
            throw new RuntimeException('reminders was created without its constraints: '.implode(', ', $missing).'.');
        }
    }
};
