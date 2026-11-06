<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime knobs for the calendar and the reminder scheduler, in the existing app_settings table and
 * read through the existing AppSetting::getValue() — no new infrastructure.
 *
 * insertOrIgnore keeps this idempotent against the unique `key` index, so a re-run cannot create
 * duplicates and a hand-deleted row can be restored by re-running. These are operators' tunables;
 * the same defaults stay written as fallbacks in the code so a missing row cannot break a path.
 *
 * `calendar.status_widening_enabled` starts at 0 on purpose. The widened column accepts
 * confirmed|cancelled|missed before any code is allowed to write them, so the gate — not the
 * schema — decides when those statuses become writable, and a partial deploy lands on the safe side.
 */
return new class extends Migration
{
    private const SETTINGS = [
        ['key' => 'reminders.missed_grace_minutes', 'value' => '30', 'type' => 'integer', 'group' => 'reminders'],
        ['key' => 'reminders.claim_lease_minutes', 'value' => '10', 'type' => 'integer', 'group' => 'reminders'],
        ['key' => 'reminders.batch_size', 'value' => '200', 'type' => 'integer', 'group' => 'reminders'],
        ['key' => 'reminders.retry_max_attempts', 'value' => '3', 'type' => 'integer', 'group' => 'reminders'],
        ['key' => 'reminders.scheduler_runs_retention_days', 'value' => '7', 'type' => 'integer', 'group' => 'reminders'],
        ['key' => 'reminders.deliveries_retention_days', 'value' => '90', 'type' => 'integer', 'group' => 'reminders'],
        ['key' => 'reminders.trim_state', 'value' => 'idle', 'type' => 'string', 'group' => 'reminders'],
        ['key' => 'calendar.range_max_items', 'value' => '500', 'type' => 'integer', 'group' => 'calendar'],
        ['key' => 'calendar.status_widening_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'calendar'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            throw new RuntimeException('app_settings does not exist; refusing to seed calendar settings into an unknown schema.');
        }

        $now = now();

        DB::table('app_settings')->insertOrIgnore(array_map(
            fn (array $setting): array => $setting + ['created_at' => $now, 'updated_at' => $now],
            self::SETTINGS
        ));

        $stored = DB::table('app_settings')->whereIn('key', self::keys())->pluck('key')->all();

        $missing = array_values(array_diff(self::keys(), (array) $stored));

        if ($missing !== []) {
            throw new RuntimeException('Calendar settings were not stored: '.implode(', ', $missing).'.');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')->whereIn('key', self::keys())->delete();
        }
    }

    private static function keys(): array
    {
        return array_column(self::SETTINGS, 'key');
    }
};
