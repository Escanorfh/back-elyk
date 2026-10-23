<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create("live_raise_hand_requests", function (Blueprint $table) {
            $table->id();
            $table->foreignId("live_session_id")->constrained()->onDelete("cascade");
            $table->foreignId("participant_id")->constrained("live_participants")->onDelete("cascade");
            $table->enum("status", ["pending", "approved", "denied"])->default("pending");
            $table->timestamp("requested_at")->useCurrent();
            $table->timestamp("resolved_at")->nullable();
            $table->foreignId("resolved_by")->nullable()->constrained("users")->onDelete("set null");
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("live_raise_hand_requests");
    }
};