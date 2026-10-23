<?php
namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\IntakeBattery;
use App\Models\Exam;
use Illuminate\Http\Request;

class IntakeController extends Controller {

    public static function startLogic($user) {
        if ($user->role !== 'patient') return null;

        $battery = IntakeBattery::firstOrCreate(
            ['patient_id' => $user->id],
            ['status' => 'in_progress']
        );

        // If it was just created, it will have no items
        if ($battery->wasRecentlyCreated || $battery->items()->count() === 0) {
            $exams = Exam::where('is_active', true)->get()->shuffle();
            
            if ($exams->isEmpty()) {
                // If there are no exams, just mark it completed instantly
                $battery->update(['status' => 'completed', 'completed_at' => now()]);
                $user->update(['intake_completed_at' => now()]);
                return $battery;
            }

            foreach ($exams as $index => $exam) {
                $battery->items()->create([
                    'exam_id' => $exam->id,
                    'order' => $index + 1,
                    'status' => $index === 0 ? 'active' : 'pending'
                ]);
            }
        }

        return $battery;
    }

    public function start(Request $request) {
        $battery = self::startLogic($request->user());
        if (!$battery) abort(403);
        return response()->json(['data' => $battery, 'message' => 'Intake started']);
    }

    public function status(Request $request) {
        $battery = IntakeBattery::with(['items.exam', 'currentItem.exam'])
            ->where('patient_id', $request->user()->id)
            ->first();

        if (!$battery) {
            return response()->json([
                'data' => [
                    'status' => 'completed',
                    'total' => 0,
                    'completed' => 0,
                    'current' => null
                ]
            ]);
        }

        $total = $battery->items()->count();
        $completed = $battery->items()->where('status', 'completed')->count();

        return response()->json([
            'data' => [
                'status' => $battery->status,
                'total' => $total,
                'completed' => $completed,
                'current' => $battery->currentItem ? [
                    'intake_battery_item_id' => $battery->currentItem->id,
                    'exam' => $battery->currentItem->exam
                ] : null
            ]
        ]);
    }
}
