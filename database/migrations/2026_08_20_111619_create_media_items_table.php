<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("media_items", function (Blueprint $table) {
            $table->id();
            $table->foreignId("disorder_id")->constrained()->cascadeOnDelete();
            $table->enum("type", ["audio", "pdf"]);
            $table->string("title");
            $table->string("title_ar")->nullable();
            $table->text("description")->nullable();
            $table->string("file_path");
            $table->integer("duration_seconds")->nullable();
            $table->foreignId("uploaded_by")->constrained("users")->cascadeOnDelete();
            $table->boolean("is_active")->default(true);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("media_items");
    }
};
