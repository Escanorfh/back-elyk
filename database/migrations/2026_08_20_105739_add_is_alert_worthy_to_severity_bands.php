<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("severity_bands", function (Blueprint $table) {
            $table->boolean("is_alert_worthy")->default(false)->after("label_ar");
        });
    }
    public function down(): void {
        Schema::table("severity_bands", function (Blueprint $table) {
            $table->dropColumn("is_alert_worthy");
        });
    }
};
