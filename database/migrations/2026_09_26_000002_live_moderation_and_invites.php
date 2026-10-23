<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // المرضى المدعوون لجلسة لايف (يختارهم الأدمن — بنفس المرض).
        Schema::create("live_session_patients", function (Blueprint $table) {
            $table->id();
            $table->foreignId("live_session_id")->constrained()->onDelete("cascade");
            $table->foreignId("patient_id")->constrained("users")->onDelete("cascade");
            $table->timestamps();
            $table->unique(["live_session_id", "patient_id"]);
        });

        // تثبيت تعليق في الشات.
        Schema::table("live_chat_messages", function (Blueprint $table) {
            $table->boolean("is_pinned")->default(false)->after("body");
        });

        // حظر مريض من الشات (ban).
        Schema::table("live_participants", function (Blueprint $table) {
            $table->boolean("is_chat_banned")->default(false)->after("can_publish_audio");
        });
    }

    public function down(): void {
        Schema::dropIfExists("live_session_patients");
        Schema::table("live_chat_messages", function (Blueprint $table) {
            $table->dropColumn("is_pinned");
        });
        Schema::table("live_participants", function (Blueprint $table) {
            $table->dropColumn("is_chat_banned");
        });
    }
};
