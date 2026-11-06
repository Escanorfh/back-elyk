<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's stated timezone. NULL means "no preference declared" and is never treated as UTC:
 * the rule is `user_settings.timezone ?? config('app.timezone')`, applied only when an item is
 * created. Display uses the device timezone; day buckets use the device calendar.
 */
return new class extends Migration
{
    private const TABLE = 'user_settings';

    private const COLUMN = 'timezone';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('user_settings does not exist; refusing to create it from a calendar migration.');
        }

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->string(self::COLUMN, 64)->nullable());

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            throw new RuntimeException('user_settings.timezone was not created.');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn(self::COLUMN));
        }
    }
};
