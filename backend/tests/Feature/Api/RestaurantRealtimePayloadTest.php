<?php

namespace Tests\Feature\Api;

use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\User;
use App\Support\RestaurantRealtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantRealtimePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_sent_includes_a_patchable_snapshot_for_kitchen_and_floor(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        $owner = User::query()->where('email', 'asha@example.test')->sole();
        $hotel = Hotel::query()->sole();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'realtime-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        $session = DiningSession::query()->with(['diningTable', 'waiter', 'orders.items'])->findOrFail($sessionId);
        $payload = RestaurantRealtime::payload($session, [
            'kitchen_items' => $session->orders->flatMap->items->map(fn ($item) => RestaurantRealtime::kitchenItem($item))->values()->all(),
        ]);

        $this->assertSame('Table 3', $payload['table_name']);
        $this->assertSame('occupied', $payload['session_status']);
        $this->assertSame(1, $payload['kitchen_progress']['pending']);
        $this->assertCount(1, $payload['kitchen_items']);
        $this->assertSame('Curry', $payload['kitchen_items'][0]['item_name']);
        $this->assertSame($station->id, $payload['kitchen_items'][0]['kitchen_station_id']);
    }
}
