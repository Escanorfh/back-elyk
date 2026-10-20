<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table("users", function (Blueprint $table) {
            $table->boolean("session_eligible")->default(false);
            $table->timestamp("session_eligible_at")->nullable();
            $table->enum("eligibility_source", ["task_completion", "manual_grant"])->nullable();
        });
    }
    public function down(): void {
        Schema::table("users", function (Blueprint $table) {
            $table->dropColumn(["session_eligible", "session_eligible_at", "eligibility_source"]);
        });
    }
};