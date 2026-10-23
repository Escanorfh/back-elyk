<?php
$base = __DIR__;

function getFilename($pattern) {
    global $base;
    $files = glob($base . '/database/migrations/*' . $pattern . '*.php');
    return $files[0] ?? null;
}

function writeMigration($pattern, $content) {
    $file = getFilename($pattern);
    if ($file) {
        file_put_contents($file, $content);
        echo "Updated: " . basename($file) . "\n";
    } else {
        echo "Could not find migration matching: $pattern\n";
    }
}

writeMigration('add_is_alert_worthy_to_severity_bands', '<?php
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
');

writeMigration('create_fcm_tokens_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("fcm_tokens", function (Blueprint $table) {
            $table->id();
            $table->foreignId("user_id")->constrained()->cascadeOnDelete();
            $table->string("token")->unique();
            $table->string("device_type")->nullable(); // ios or android
            $table->timestamp("last_used_at")->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("fcm_tokens");
    }
};
');

writeMigration('create_chat_threads_table', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("chat_threads", function (Blueprint $table) {
            $table->id();
            $table->foreignId("patient_id")->unique()->constrained("users")->cascadeOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists("chat_threads");
    }
};
');

writeMigration('create_chat_messages_table', '<?php
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
');

echo "Migrations populated.\n";
