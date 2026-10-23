<?php
namespace App\Listeners;
use App\Events\AttemptFinalized;
use App\Models\User;
use App\Notifications\SevereExamResultNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifySevereResult implements ShouldQueue {
    public function handle(AttemptFinalized $event): void {
        $result = $event->result;
        
        $isSevere = false;
        if ($result->severityBand && $result->severityBand->is_alert_worthy) {
            $isSevere = true;
        } else {
            foreach ($result->attempt->subscaleResults as $subRes) {
                if ($subRes->severityBand && $subRes->severityBand->is_alert_worthy) {
                    $isSevere = true;
                    break;
                }
            }
        }

        if ($isSevere) {
            $admins = User::where("role", "admin")->get();
            \Illuminate\Support\Facades\Notification::send($admins, new SevereExamResultNotification($result));
        }
    }
}
