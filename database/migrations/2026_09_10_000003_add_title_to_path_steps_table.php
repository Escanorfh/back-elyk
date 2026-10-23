<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("path_steps", function (Blueprint $table) {
            $table->string("title", 500)->nullable()->after("order");
        });
    }
    public function down(): void {
        Schema::table("path_steps", function (Blueprint $table) {
            $table->dropColumn("title");
        });
    }
};