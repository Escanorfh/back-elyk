<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * personal_tasks: to-dos that are not a clinical path step — the patient's own tasks and a
 * therapist follow-up that never became homework. Clinical work stays in path_steps.
 *
 * `due_at` NULL means the No Date bucket, never "today". `overdue` is computed from
 * (due_at, status, now) in one place and is deliberately not a stored status.
 */
return new class extends Migration
{
    private const TABLE = 'personal_tasks';

    private const INDEX = 'ix_tasks_owner_due_status';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('personal_tasks already exists; this migration creates that table, so it refuses to run against one it did not create.');
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 32)->default('personal_task');
            $table->dateTime('due_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('priority', 8)->default('normal');
            $table->string('status', 16)->default('pending');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->index(['user_id', 'due_at', 'status'], self::INDEX);
        });

        if (! in_array(self::INDEX, array_column(Schema::getIndexes(self::TABLE), 'name'), true)) {
            throw new RuntimeException(self::INDEX.' is absent after creating personal_tasks.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
