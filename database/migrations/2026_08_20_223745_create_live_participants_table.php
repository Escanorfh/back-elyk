<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create("live_participants", function (Blueprint $table) {
            $table->id();
            $table->foreignId("live_session_id")->constrained()->onDelete("cascade");
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->enum("role", ["admin", "patient"]);
            $table->string("anonymous_label");
            $table->string("livekit_identity")->unique();
            $table->enum("status", ["connected", "raised_hand", "speaking", "left"])->default("connected");
            $table->boolean("can_publish_audio")->default(false);
            $table->timestamp("joined_at");
            $table->timestamp("left_at")->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("live_participants");
    }
};