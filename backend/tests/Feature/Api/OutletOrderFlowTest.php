<?php

namespace Tests\Feature\Api;

use App\Events\RestaurantUpdated;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OutletOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_payload_includes_outlet_order_flow(): void
    {
        [$owner] = $this->ownerContext();

        $this->actingAs($owner, 'web')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.outlets.0.order_flow', 'kitchen');
    }

    public function test_direct_bill_outlet_skips_kitchen_keeps_one_line_and_still_bills(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Chicken Lollipop', 'selling_price' => 100, 'track_inventory' => true]);
        InventoryStock::query()->create(['outlet_id' => $outlet->id, 'product_id' => $product->id, 'quantity' => 10]);
        $outlet->update(['order_flow' => 'direct_bill']);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Bar 1', 'code' => 'B1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'direct-bill-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 4]]])
            ->assertCreated()
            ->assertJsonPath('data.orders.0.items.0.fulfillment_mode', 'direct')
            ->assertJsonPath('data.orders.0.items.0.status', 'ready')
            ->assertJsonPath('data.orders.0.items.0.kitchen_station_id', null)
            ->assertJsonPath('data.total_amount', '400.00');

        $this->assertSame(1, OrderItem::query()->count());
        $this->assertSame('6.000', InventoryStock::query()->sole()->quantity);
        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$station->id}/queue")
            ->assertOk()->assertJsonCount(0, 'data.items');

        $item = OrderItem::query()->sole();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/cancel", ['reason' => 'Guest changed quantity'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'direct-bill-reorder')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '200.00');
    }

    public function test_switching_one_outlet_to_direct_bill_clears_its_kitchen_and_leaves_the_other(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $restaurant = $hotel->outlets()->sole();
        $bar = Outlet::query()->create(['hotel_id' => $hotel->id, 'name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR']);
        $hot = KitchenStation::query()->create(['outlet_id' => $restaurant->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $barStation = KitchenStation::query()->create(['outlet_id' => $bar->id, 'name' => 'Bar Station', 'code' => 'BARST']);
        $curry = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $hot->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 120, 'track_inventory' => true]);
        $drink = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $barStation->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Cocktail', 'selling_price' => 80, 'track_inventory' => true]);
        InventoryStock::query()->create(['outlet_id' => $restaurant->id, 'product_id' => $curry->id, 'quantity' => 5]);
        $drinkStock = InventoryStock::query()->create(['outlet_id' => $bar->id, 'product_id' => $drink->id, 'quantity' => 5]);
        $dining = DiningTable::query()->create(['outlet_id' => $restaurant->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $barTable = DiningTable::query()->create(['outlet_id' => $bar->id, 'name' => 'Bar 1', 'code' => 'B1', 'capacity' => 2]);

        $restaurantSession = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$dining->id}/sessions")->json('data.id');
        $barSession = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$barTable->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'rest-order')->postJson("/api/v1/dining-sessions/{$restaurantSession}/orders", ['items' => [['product_id' => $curry->id, 'quantity' => 1]]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'bar-order')->postJson("/api/v1/dining-sessions/{$barSession}/orders", ['items' => [['product_id' => $drink->id, 'quantity' => 2]]])->assertCreated();
        $this->assertSame(3, OrderItem::query()->where('fulfillment_mode', 'kitchen')->where('status', 'pending')->count());

        Event::fake([RestaurantUpdated::class]);
        $this->actingAs($owner, 'web')->putJson("/api/v1/outlets/{$bar->id}", [
            'order_flow' => 'direct_bill',
        ])->assertOk()->assertJsonPath('data.order_flow', 'direct_bill');

        $barItems = OrderItem::query()->whereHas('order', fn ($query) => $query->where('dining_session_id', $barSession))->get();
        $this->assertTrue($barItems->every(fn (OrderItem $item) => $item->fulfillment_mode === 'direct' && $item->status === 'ready' && $item->kitchen_station_id === null));
        $this->assertSame('3.000', $drinkStock->fresh()->quantity);
        $restaurantItem = OrderItem::query()->whereHas('order', fn ($query) => $query->where('dining_session_id', $restaurantSession))->sole();
        $this->assertSame('kitchen', $restaurantItem->fulfillment_mode);
        $this->assertSame('pending', $restaurantItem->status);

        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$barStation->id}/queue")->assertOk()->assertJsonCount(0, 'data.items');
        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$hot->id}/queue")->assertOk()->assertJsonCount(1, 'data.items');

        Event::assertDispatched(RestaurantUpdated::class, fn (RestaurantUpdated $event) => $event->type === 'outlet_flow_changed' && $event->outletId === $bar->id);
        Event::assertNotDispatched(RestaurantUpdated::class, fn (RestaurantUpdated $event) => $event->type === 'item_ready');
    }

    public function test_kitchen_outlet_still_creates_kitchen_tickets(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'kitchen-still-works')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated()
            ->assertJsonPath('data.orders.0.items.0.fulfillment_mode', 'kitchen')
            ->assertJsonPath('data.orders.0.items.0.status', 'pending');
        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$station->id}/queue")->assertOk()->assertJsonCount(1, 'data.items');
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha-flow@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha-flow@example.test')->sole(), Hotel::query()->sole()];
    }
}
