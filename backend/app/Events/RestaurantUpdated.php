<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RestaurantUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public int $tries = 5;

    public string $queue = 'broadcasts';

    public readonly string $eventId;

    public readonly string $occurredAt;

    public function __construct(
        public readonly string $type,
        public readonly int $hotelId,
        public readonly int $outletId,
        public readonly ?int $tableId = null,
        public readonly ?int $sessionId = null,
        public readonly ?int $orderId = null,
        public readonly ?int $itemId = null,
        public readonly ?int $waiterId = null,
        public readonly ?int $stationId = null,
        public readonly array $data = [],
    ) {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = now()->toISOString();
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("hotel.{$this->hotelId}.outlet.{$this->outletId}")];
        if ($this->tableId) {
            $channels[] = new PrivateChannel("hotel.{$this->hotelId}.table.{$this->tableId}");
        }
        if ($this->stationId) {
            $channels[] = new PrivateChannel("hotel.{$this->hotelId}.kitchen.{$this->stationId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return "restaurant.{$this->type}";
    }

    public function broadcastWith(): array
    {
        return [
            'schema_version' => 1,
            'event_id' => $this->eventId,
            'type' => $this->type,
            'hotel_id' => $this->hotelId,
            'outlet_id' => $this->outletId,
            'table_id' => $this->tableId,
            'session_id' => $this->sessionId,
            'order_id' => $this->orderId,
            'item_id' => $this->itemId,
            'waiter_id' => $this->waiterId,
            'station_id' => $this->stationId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
        ];
    }

    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }
}
