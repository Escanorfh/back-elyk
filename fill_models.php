<?php
$base = __DIR__;

function writeModel($file, $content) {
    global $base;
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeModel('app/Models/FcmToken.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FcmToken extends Model {
    protected $fillable = ["user_id", "token", "device_type", "last_used_at"];
    protected $casts = ["last_used_at" => "datetime"];
    public function user() { return $this->belongsTo(User::class); }
}
');

writeModel('app/Models/ChatThread.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatThread extends Model {
    protected $fillable = ["patient_id"];
    public function patient() { return $this->belongsTo(User::class, "patient_id"); }
    public function messages() { return $this->hasMany(ChatMessage::class, "thread_id"); }
}
');

writeModel('app/Models/ChatMessage.php', '<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatMessage extends Model {
    protected $fillable = ["thread_id", "sender_id", "sender_role", "body", "read_at"];
    protected $casts = ["read_at" => "datetime"];
    public function thread() { return $this->belongsTo(ChatThread::class, "thread_id"); }
    public function sender() { return $this->belongsTo(User::class, "sender_id"); }
}
');

// Update User Model
$userModel = file_get_contents($base . '/app/Models/User.php');
$methods = '
    public function fcmTokens() { return $this->hasMany(FcmToken::class); }
    public function chatThread() { return $this->hasOne(ChatThread::class, "patient_id"); }
    public function messages() { return $this->hasMany(ChatMessage::class, "sender_id"); }
}';
$userModel = preg_replace('/}\s*$/', $methods, $userModel);
file_put_contents($base . '/app/Models/User.php', $userModel);

// Update SeverityBand model to add is_alert_worthy
$bandModel = file_get_contents($base . '/app/Models/SeverityBand.php');
if (strpos($bandModel, 'is_alert_worthy') === false) {
    $bandModel = str_replace("'label',", "'label', 'is_alert_worthy',", $bandModel);
    $bandModel = str_replace('protected $casts = [', "protected \$casts = [\n        'is_alert_worthy' => 'boolean',", $bandModel);
    file_put_contents($base . '/app/Models/SeverityBand.php', $bandModel);
}

echo "Models populated.\n";
