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

    public function test_direct_bill_order_appears_on_live_board_and_track_done_does_not_change_item_status(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $outlet->update(['order_flow' => 'direct_bill']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'fulfillment_mode' => 'direct', 'name' => 'Water Bottle', 'selling_price' => 40, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-first')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'live-second')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated();

        $board = $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.queue')
            ->assertJsonCount(0, 'data.done');
        $firstId = $board->json('data.queue.0.id');
        $secondId = $board->json('data.queue.1.id');
        $this->assertGreaterThan($firstId, $secondId);

        $item = OrderItem::query()->where('order_id', $firstId)->sole();
        $status = $item->status;
        $readyAt = $item->ready_at?->toISOString();
        $total = DiningSession::query()->findOrFail($sessionId)->total_amount;

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$firstId}/track-done")
            ->assertOk()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.tracked_done_at', fn ($value) => filled($value));

        $item->refresh();
        $this->assertSame($status, $item->status);
        $this->assertSame($readyAt, $item->ready_at?->toISOString());
        $this->assertSame($total, DiningSession::query()->findOrFail($sessionId)->fresh()->total_amount);

        $after = $this->actingAs($owner, 'web')->getJson("/api/v1/live-board?outlet_id={$outlet->id}")->assertOk();
        $this->assertSame([$secondId], collect($after->json('data.queue'))->pluck('id')->all());
        $this->assertSame([$firstId], collect($after->json('data.done'))->pluck('id')->all());
    }

    public function test_paid_session_and_old_done_tickets_leave_the_live_board(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $outlet->update(['order_flow' => 'direct_bill']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'fulfillment_mode' => 'direct', 'name' => 'Tea', 'selling_price' => 10, 'track_inventory' => false]);
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
