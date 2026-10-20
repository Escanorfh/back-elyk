<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("media_items", function (Blueprint $table) {
            $table->tinyInteger("stage")->nullable()->after("type");
        });
    }
    public function down(): void {
        Schema::table("media_items", function (Blueprint $table) {
            $table->dropColumn("stage");
        });
    }
};