<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("live_sessions", function (Blueprint $table) {
            $table->timestamp("scheduled_at")->nullable()->after("livekit_room_name");
            $table->foreignId("patient_id")->nullable()->after("admin_id")->constrained("users")->onDelete("cascade");
        });
    }

    public function down(): void {
        Schema::table("live_sessions", function (Blueprint $table) {
            $table->dropConstrainedForeignId("patient_id");
            $table->dropColumn("scheduled_at");
        });
    }
};
