<?php
namespace App\Listeners;
use App\Events\AttemptFinalized;
use App\Notifications\ExamResultReadyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPatientResultReady implements ShouldQueue {
    public function handle(AttemptFinalized $event): void {
        $result = $event->result;
        $patient = $result->attempt->patient;
        if ($patient) {
            $patient->notify(new ExamResultReadyNotification($result));
        }
    }
}
