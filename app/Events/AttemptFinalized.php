<?php
namespace App\Events;
use App\Models\AttemptResult;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class AttemptFinalized {
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
}
