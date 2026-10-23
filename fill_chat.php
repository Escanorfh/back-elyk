<?php
$base = __DIR__;

function writeClass($file, $content) {
    global $base;
    $dir = dirname($base . '/' . $file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeClass('app/Policies/ChatThreadPolicy.php', '<?php
namespace App\Policies;
use App\Models\User;
use App\Models\ChatThread;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatThreadPolicy {
    use HandlesAuthorization;
    public function view(User $user, ChatThread $thread) {
        return $user->role === "admin" || $user->id === $thread->patient_id;
    }
    public function update(User $user, ChatThread $thread) {
        return $user->role === "admin" || $user->id === $thread->patient_id;
    }
}
');

writeClass('app/Http/Controllers/ChatController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Events\ChatMessageSent;
use App\Notifications\NewChatMessageNotification;

class ChatController extends Controller {
    public function threads(Request $request) {
        if ($request->user()->role === "patient") {
            $threads = ChatThread::where("patient_id", $request->user()->id)->get();
        } else {
            $threads = ChatThread::with("patient")->orderBy("updated_at", "desc")->get();
        }
        return response()->json(["data" => $threads]);
    }
    
    public function messages(Request $request, ChatThread $thread) {
        if ($request->user()->cannot("view", $thread)) abort(403);
        $messages = $thread->messages()->with("sender")->orderBy("created_at", "desc")->paginate(20);
        return response()->json($messages);
    }

    public function sendMessage(Request $request) {
        $validated = $request->validate([
            "body" => "required|string",
            "patient_id" => "integer|exists:users,id"
        ]);

        $user = $request->user();
        if ($user->role === "patient") {
            $thread = ChatThread::firstOrCreate(["patient_id" => $user->id]);
        } else {
            if (empty($validated["patient_id"])) abort(400, "patient_id required for admin");
            $thread = ChatThread::firstOrCreate(["patient_id" => $validated["patient_id"]]);
        }

        $message = $thread->messages()->create([
            "sender_id" => $user->id,
            "sender_role" => $user->role,
            "body" => $validated["body"]
        ]);
        
        $thread->touch();

        // Broadcast to private channel
        broadcast(new ChatMessageSent($message))->toOthers();

        // Notify the OTHER party
        if ($user->role === "patient") {
            // Admins generally don\'t get individual unread counts for all patients unless specifically assigned,
            // but we can send an alert if needed. For now, they monitor the thread list.
        } else {
            $thread->patient->notify(new NewChatMessageNotification($message));
        }

        return response()->json(["data" => $message]);
    }

    public function read(Request $request, ChatThread $thread) {
        if ($request->user()->cannot("update", $thread)) abort(403);
        
        // Mark messages as read that are NOT sent by the current user
        $thread->messages()->where("sender_id", "!=", $request->user()->id)->whereNull("read_at")->update(["read_at" => now()]);
        return response()->json(["message" => "Marked as read"]);
    }
}
');

writeClass('app/Http/Controllers/FcmTokenController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

class FcmTokenController extends Controller {
    public function store(Request $request) {
        $validated = $request->validate([
            "token" => "required|string",
            "device_type" => "nullable|string"
        ]);
        
        $request->user()->fcmTokens()->updateOrCreate(
            ["token" => $validated["token"]],
            ["device_type" => $validated["device_type"], "last_used_at" => now()]
        );
        return response()->json(["message" => "Token registered"]);
    }
    public function destroy(Request $request) {
        $validated = $request->validate(["token" => "required|string"]);
        $request->user()->fcmTokens()->where("token", $validated["token"])->delete();
        return response()->json(["message" => "Token deleted"]);
    }
}
');

writeClass('app/Http/Controllers/NotificationController.php', '<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

class NotificationController extends Controller {
    public function index(Request $request) {
        return response()->json($request->user()->notifications()->paginate(20));
    }
    public function read(Request $request, $id) {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        return response()->json(["message" => "Marked as read"]);
    }
    public function readAll(Request $request) {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(["message" => "All marked as read"]);
    }
}
');

// Update API routes
$routes = file_get_contents($base . '/routes/api.php');
$chatRoutes = '
        // Chat & Notifications
        Route::get("/notifications", [\App\Http\Controllers\NotificationController::class, "index"]);
        Route::post("/notifications/{id}/read", [\App\Http\Controllers\NotificationController::class, "read"]);
        Route::post("/notifications/read-all", [\App\Http\Controllers\NotificationController::class, "readAll"]);
        
        Route::post("/fcm-tokens", [\App\Http\Controllers\FcmTokenController::class, "store"]);
        Route::delete("/fcm-tokens", [\App\Http\Controllers\FcmTokenController::class, "destroy"]);
        
        Route::get("/chat/threads", [\App\Http\Controllers\ChatController::class, "threads"]);
        Route::get("/chat/threads/{thread}/messages", [\App\Http\Controllers\ChatController::class, "messages"]);
        Route::post("/chat/messages", [\App\Http\Controllers\ChatController::class, "sendMessage"]);
        Route::post("/chat/threads/{thread}/read", [\App\Http\Controllers\ChatController::class, "read"]);
';
$routes = preg_replace('/(\/\/ Admin Routes)/', $chatRoutes . "\n        $1", $routes);
file_put_contents($base . '/routes/api.php', $routes);

echo "Chat classes populated.\n";
