<?php
namespace Modules\Order\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderOfferedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $driverId,
        public readonly array  $order,
        public readonly int    $ttl,
        public readonly string $offeredAt = '',
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("driver.{$this->driverId}")];
    }

    public function broadcastAs(): string
    {
        return 'order.offered';
    }

    public function broadcastWith(): array
    {
        return [
            'order'      => $this->order,
            'ttl'        => $this->ttl,
            'offered_at' => $this->offeredAt ?: now()->toIso8601String(),
        ];
    }
}
