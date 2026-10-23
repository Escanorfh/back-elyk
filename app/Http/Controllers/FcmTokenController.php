<?php
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
