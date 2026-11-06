<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reminder_deliveries: the send log, and the only thing that makes a retry safe.
 *
 * One composite UNIQUE is the idempotency guard — inserting the row and sending are the same
 * decision, so a duplicate insert means "already sent" rather than a second notification. A hash
 * column for the same purpose was dropped: it duplicated this key, cost 72 bytes per row on the
 * busiest table here, and Eloquent never reads the diagnostic MySQL emits for the ignored branch.
 *
 * `target_key` is derived server-side only ('db', 'local:default', later 'fcm:<id>'). A client-
 * supplied device key was removed from the identity on purpose: it would let one device invent
 * unbounded keys and multiply delivery rows for the same reminder. varchar(24) for the channel,
 * not 16, because 'local_notification' is 18 characters and strict mode would reject it at the
 * first personal reminder — in production, not in the first test.
 */
return new class extends Migration
{
    private const TABLE = 'reminder_deliveries';

    private const UNIQUE = 'uq_deliv_target';

    private const INDEX = 'ix_deliv_retry';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('reminder_deliveries already exists; this migration creates that table, so it refuses to run against one it did not create.');
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained('reminders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('target_key', 64);
            $table->string('status', 16)->default('pending');
            $table->string('provider_message_id', 128)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['reminder_id', 'user_id', 'channel', 'target_key'], self::UNIQUE);
            $table->index(['status', 'next_retry_at'], self::INDEX);
        });

        $names = (array) array_column(Schema::getIndexes(self::TABLE), 'name');
        $missing = array_values(array_diff([self::UNIQUE, self::INDEX], $names));

        if ($missing !== []) {
            throw new RuntimeException('reminder_deliveries was created without its constraints: '.implode(', ', $missing).'.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
