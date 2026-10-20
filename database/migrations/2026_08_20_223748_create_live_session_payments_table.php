<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create("live_session_payments", function (Blueprint $table) {
            $table->id();
            $table->foreignId("live_session_id")->constrained()->onDelete("cascade");
            $table->foreignId("patient_id")->constrained("users")->onDelete("cascade");
            $table->integer("amount");
            $table->string("currency", 3);
            $table->enum("status", ["pending", "paid", "failed", "refunded"])->default("pending");
            $table->string("provider_reference")->nullable();
            $table->timestamp("paid_at")->nullable();
            $table->timestamps();
            
            $table->unique(["live_session_id", "patient_id"]);
        });
    }
    public function down(): void {
        Schema::dropIfExists("live_session_payments");
    }
};