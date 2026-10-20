<?php

namespace App\Listeners;

use App\Events\AttemptFinalized;
use App\Models\User;
use App\Notifications\MaxLevelBreachNotification;
use Illuminate\Support\Facades\Notification;

/**
 * لو المريض عدّى الـ max level في أي فورم => إشعار أحمر فوري لكل الأدمن.
 * متعمد synchronous (بدون ShouldQueue) عشان "فوراً" — لا يعتمد على queue worker.
 */
class NotifyMaxLevelBreach
{
    public function handle(AttemptFinalized $event): void
    {
        $result = $event->result;
        $exam = $result->attempt->exam()->first();

        if (!$exam || $exam->max_level_score === null) return;
        if ($result->total_score < (int) $exam->max_level_score) return;

        $admins = User::where('role', 'admin')->get();
        if ($admins->isEmpty()) return;

        Notification::send(
            $admins,
            new MaxLevelBreachNotification($result, (int) $exam->max_level_score)
        );
    }
}
