<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class PurgeStaleTokens extends Command
{
    protected $signature = 'auth:purge-stale-tokens';
    protected $description = 'Purge personal access tokens inactive for 60+ days';

    public function handle()
    {
        $cutoff = now()->subDays(60);

        $deleted = PersonalAccessToken::where(function ($query) use ($cutoff) {
            $query->where('last_used_at', '<', $cutoff)
                  ->orWhere(function ($q) use ($cutoff) {
                      $q->whereNull('last_used_at')
                        ->where('created_at', '<', $cutoff);
                  });
        })->delete();

        $this->info("Purged {$deleted} stale tokens.");
    }
}
