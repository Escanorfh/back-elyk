<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create("live_sessions", function (Blueprint $table) {
            $table->id();
            $table->foreignId("admin_id")->constrained("users")->onDelete("cascade");
            $table->string("title");
            $table->enum("status", ["scheduled", "live", "ended"])->default("scheduled");
            $table->string("livekit_room_name")->unique();
            $table->integer("fee_amount")->nullable();
            $table->string("fee_currency")->nullable();
            $table->timestamp("started_at")->nullable();
            $table->timestamp("ended_at")->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("live_sessions");
    }
};