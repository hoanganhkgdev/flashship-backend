<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class DispatchStateChanged implements ShouldBroadcastNow
{
    public function broadcastOn(): Channel
    {
        return new Channel('dispatch-monitor');
    }

    public function broadcastAs(): string
    {
        return 'state.changed';
    }
}
