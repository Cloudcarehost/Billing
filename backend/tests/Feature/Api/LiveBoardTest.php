<?php

namespace Tests\Feature\Api;

use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_bill_live_board_only_tracks_kitchen_route_items_without_changing_item_status(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $outlet->update(['order_flow' => 'direct_bill']);
        $water = Product::query()->create(['hotel_id' => $hotel->id, 'fulfillment_mode' => 'direct', 'name' => 'Water Bottle', 'selling_price' => 40, 'track_inventory' => false]);
        $curry = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $dal = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Dal', 'selling_price' => 80, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-mixed')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [
            ['product_id' => $water->id, 'quantity' => 1],
            ['product_id' => $curry->id, 'quantity' => 1],
            ['product_id' => $dal->id, 'quantity' => 1],
        ]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-direct-only')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $water->id, 'quantity' => 2]]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-kitchen-only')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $curry->id, 'quantity' => 2]]])->assertCreated();

        [$mixedOrder, $directOrder, $kitchenOrder] = Order::query()->orderBy('id')->get()->all();
        $this->assertTrue($mixedOrder->items()->get()->every(fn (OrderItem $item) => $item->fulfillment_mode === 'direct' && $item->status === 'ready'));
        $this->assertSame('direct', $mixedOrder->items()->where('product_id', $water->id)->sole()->service_route);
        $this->assertSame('kitchen', $mixedOrder->items()->where('product_id', $curry->id)->sole()->service_route);

        $board = $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.queue')
            ->assertJsonCount(0, 'data.done');
        $this->assertSame([$mixedOrder->id, $kitchenOrder->id], collect($board->json('data.queue'))->pluck('id')->all());
        $this->assertSame(['Curry', 'Dal'], collect($board->json('data.queue.0.items'))->pluck('item_name')->all());
        $this->assertNotContains($directOrder->id, collect($board->json('data.queue'))->pluck('id')->all());

        $itemsBefore = $mixedOrder->items()->get()->mapWithKeys(fn (OrderItem $item) => [$item->id => [$item->status, $item->ready_at?->toISOString()]]);
        $total = DiningSession::query()->findOrFail($sessionId)->total_amount;
        $curryItem = $mixedOrder->items()->where('product_id', $curry->id)->sole();

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$curryItem->id}/track-done")
            ->assertOk()
            ->assertJsonPath('data.completed_item_id', $curryItem->id)
            ->assertJsonPath('data.ticket_complete', false)
            ->assertJsonCount(1, 'data.queue_ticket.items')
            ->assertJsonPath('data.queue_ticket.items.0.item_name', 'Dal');
        $this->assertNotNull($curryItem->fresh()->tracked_done_at);
        $partial = $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")->assertOk();
        $this->assertSame(['Dal'], collect($partial->json('data.queue.0.items'))->pluck('item_name')->all());

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$directOrder->id}/track-done")->assertUnprocessable();
        $dalItem = $mixedOrder->items()->where('product_id', $dal->id)->sole();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$dalItem->id}/track-done")
            ->assertOk()
            ->assertJsonPath('data.ticket_complete', true)
            ->assertJsonCount(2, 'data.done_ticket.items')
            ->assertJsonPath('data.done_ticket.tracked_done_at', fn ($value) => filled($value));

        $itemsAfter = $mixedOrder->items()->get()->mapWithKeys(fn (OrderItem $item) => [$item->id => [$item->status, $item->ready_at?->toISOString()]]);
        $this->assertSame($itemsBefore->all(), $itemsAfter->all());
        $this->assertSame($total, DiningSession::query()->findOrFail($sessionId)->fresh()->total_amount);

        $after = $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")->assertOk();
        $this->assertSame([$kitchenOrder->id], collect($after->json('data.queue'))->pluck('id')->all());
        $this->assertSame([$mixedOrder->id], collect($after->json('data.done'))->pluck('id')->all());
    }

    public function test_paid_session_and_old_done_tickets_leave_the_live_board(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $outlet->update(['order_flow' => 'direct_bill']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Tea', 'selling_price' => 10, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-pay-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::query()->sole();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/track-done")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")->assertOk()->assertJsonCount(1, 'data.done');

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-pay')->postJson("/api/v1/invoices/{$invoiceId}/payments", ['method' => 'cash', 'amount' => 10])->assertOk();

        $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.queue')
            ->assertJsonCount(0, 'data.done');

        $openId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-old-done')->postJson("/api/v1/dining-sessions/{$openId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $stale = Order::query()->where('dining_session_id', $openId)->sole();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$stale->id}/track-done")->assertOk();
        $stale->update(['tracked_done_at' => now()->subHours(4)]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.queue')
            ->assertJsonCount(0, 'data.done');
    }

    public function test_billing_can_complete_grouped_kitchen_items_in_one_idempotent_request(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $outlet->update(['order_flow' => 'direct_bill']);
        $kitchenProduct = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $directProduct = Product::query()->create(['hotel_id' => $hotel->id, 'fulfillment_mode' => 'direct', 'name' => 'Water', 'selling_price' => 20, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'billing-attention-first')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [
            ['product_id' => $kitchenProduct->id, 'quantity' => 2],
            ['product_id' => $directProduct->id, 'quantity' => 1],
        ]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'billing-attention-second')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [
            ['product_id' => $kitchenProduct->id, 'quantity' => 4],
        ]])->assertCreated();

        $kitchenItems = OrderItem::query()->where('service_route', 'kitchen')->orderBy('id')->get();
        $directItem = OrderItem::query()->where('service_route', 'direct')->sole();
        $totalBefore = DiningSession::query()->findOrFail($sessionId)->total_amount;
        $statusesBefore = OrderItem::query()->pluck('status', 'id')->all();

        $this->actingAs($owner, 'web')->getJson("/api/v1/tables/status?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.0.active_session.kitchen_attention_count', fn ($count) => (float) $count === 6.0);

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'billing-attention-done')->postJson("/api/v1/dining-sessions/{$sessionId}/track-items-done", [
            'item_ids' => $kitchenItems->pluck('id')->all(),
        ])->assertOk()
            ->assertJsonCount(2, 'data.tickets')
            ->assertJsonPath('data.session.id', $sessionId);

        $this->assertSame(2, OrderItem::query()->whereIn('id', $kitchenItems->pluck('id'))->whereNotNull('tracked_done_at')->count());
        $this->assertSame(2, Order::query()->where('dining_session_id', $sessionId)->whereNotNull('tracked_done_at')->count());
        $this->assertNull($directItem->fresh()->tracked_done_at);
        $this->assertSame($statusesBefore, OrderItem::query()->pluck('status', 'id')->all());
        $this->assertSame($totalBefore, DiningSession::query()->findOrFail($sessionId)->total_amount);
        $this->actingAs($owner, 'web')->getJson("/api/v1/tables/status?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.0.active_session.kitchen_attention_count', fn ($count) => (float) $count === 0.0);
        $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.queue')
            ->assertJsonCount(2, 'data.done');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'billing-attention-retry')->postJson("/api/v1/dining-sessions/{$sessionId}/track-items-done", [
            'item_ids' => $kitchenItems->pluck('id')->all(),
        ])->assertOk();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'billing-attention-direct')->postJson("/api/v1/dining-sessions/{$sessionId}/track-items-done", [
            'item_ids' => [$directItem->id],
        ])->assertUnprocessable();
    }

    public function test_kitchen_flow_outlet_uses_station_queue_not_live_board(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'kitchen-live')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$station->id}/queue")->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.queue')
            ->assertJsonCount(0, 'data.done');
        $orderId = Order::query()->sole()->id;
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$orderId}/track-done")->assertUnprocessable();
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha-live@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha-live@example.test')->sole(), Hotel::query()->sole()];
    }
}
