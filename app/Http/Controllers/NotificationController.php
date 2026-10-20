<?php
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
