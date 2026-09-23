<?php

namespace Tests\Unit;

use App\Events\RestaurantUpdated;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use PHPUnit\Framework\TestCase;

class RestaurantUpdatedTest extends TestCase
{
    public function test_realtime_events_are_queued_after_commit_with_a_stable_contract(): void
    {
        $event = new RestaurantUpdated('item_ready', 1, 2, 3, 4, 5, 6, 7, 8, [
            'item_name' => 'Chicken Lollipop', 'quantity' => '1.000',
        ]);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame('broadcasts', $event->queue);
        $this->assertSame([
            'schema_version', 'event_id', 'type', 'hotel_id', 'outlet_id', 'table_id',
            'session_id', 'order_id', 'item_id', 'waiter_id', 'station_id', 'occurred_at', 'data',
        ], array_keys($event->broadcastWith()));
        $this->assertSame(1, $event->broadcastWith()['schema_version']);
        $this->assertSame(6, $event->broadcastWith()['item_id']);
    }
}
