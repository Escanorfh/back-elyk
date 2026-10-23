<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("chat_messages", function (Blueprint $table) {
            $table->string("file_path")->nullable()->after("body");
            $table->string("file_name")->nullable()->after("file_path");
        });
    }
    public function down(): void {
        Schema::table("chat_messages", function (Blueprint $table) {
            $table->dropColumn(["file_path", "file_name"]);
        });
    }
};