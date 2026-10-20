<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("chat_messages", function (Blueprint $table) {
            $table->id();
            $table->foreignId("thread_id")->constrained("chat_threads")->cascadeOnDelete();
            $table->foreignId("sender_id")->constrained("users")->cascadeOnDelete();
            $table->enum("sender_role", ["admin", "patient"]);
            $table->text("body");
            $table->timestamp("read_at")->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("chat_messages");
    }
};
