<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IntakeBattery;
use App\Notifications\IntakeIncompleteNotification;

class RemindStalledIntake extends Command
{
    protected $signature = 'intake:remind-stalled';
    protected $description = 'Send reminders to patients who have stalled their intake exam battery.';

    public function handle()
    {
        $cutoff = now()->subHours(24);
        
        $stalledBatteries = IntakeBattery::where('status', 'in_progress')
            ->where('last_activity_at', '<=', $cutoff)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_reminded_at')
                      ->orWhere('last_reminded_at', '<=', $cutoff);
            })
            ->with('patient')
            ->get();

        $count = 0;
        foreach ($stalledBatteries as $battery) {
            if ($battery->patient) {
                $battery->patient->notify(new IntakeIncompleteNotification());
                $battery->update(['last_reminded_at' => now()]);
                $count++;
            }
        }

        $this->info("Sent intake reminder to $count stalled patients.");
    }
}
