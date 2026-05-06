<?php
namespace Modules\Order\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverLocationUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $orderCode,
        public readonly float  $latitude,
        public readonly float  $longitude,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("order.{$this->orderCode}")];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }
}
