<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create("live_chat_messages", function (Blueprint $table) {
            $table->id();
            $table->foreignId("live_session_id")->constrained()->onDelete("cascade");
            $table->foreignId("participant_id")->constrained("live_participants")->onDelete("cascade");
            $table->text("body");
            $table->timestamps(); // includes created_at
        });
    }
    public function down(): void {
        Schema::dropIfExists("live_chat_messages");
    }
};