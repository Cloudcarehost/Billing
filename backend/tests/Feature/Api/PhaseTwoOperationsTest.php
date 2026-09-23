<?php

namespace Tests\Feature\Api;

use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\IdempotencyKey;
use App\Models\InventoryStock;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTwoOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_kitchen_lifecycle_deducts_inventory_once_then_serves_the_item(): void
    {
        [$owner, $sessionId, $item, $stock] = $this->ticketContext();

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'preparing'])
            ->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->assertSame('4.000', $stock->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'ready'])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/serve")->assertOk()->assertJsonPath('data.status', 'served');

        $this->assertSame('4.000', $stock->fresh()->quantity);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order_item.served']);
        $this->assertSame('occupied', DiningSession::query()->findOrFail($sessionId)->status);
    }

    public function test_cancelled_preparing_item_keeps_consumed_inventory_and_recalculates_the_session(): void
    {
        [$owner, $sessionId, $item, $stock] = $this->ticketContext();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'preparing'])->assertOk();

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/cancel", ['reason' => 'Guest changed their mind'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('4.000', $stock->fresh()->quantity);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'order_reversal', 'reference_id' => $item->id]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'wastage', 'reference_id' => $item->id, 'reason' => 'Prepared item cancelled']);
        $this->assertSame('0.00', DiningSession::query()->findOrFail($sessionId)->total_amount);
    }

    public function test_order_idempotency_key_creates_only_one_ticket(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 20, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'same-order-key')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", $payload)->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'same-order-key')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", $payload)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Order::query()->where('dining_session_id', $sessionId)->count());
    }

    public function test_idempotency_key_rejects_a_different_body_and_an_in_flight_request(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $tea = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 20, 'track_inventory' => false]);
        $coffee = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Coffee', 'selling_price' => 30, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'same-order-key')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $tea->id, 'quantity' => 1]]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'same-order-key')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $coffee->id, 'quantity' => 1]]])
            ->assertConflict()
            ->assertJsonPath('message', 'This idempotency key was already used with a different request.');

        $inFlightPayload = ['items' => [['product_id' => $tea->id, 'quantity' => 1]]];
        IdempotencyKey::query()->create([
            'hotel_id' => $hotel->id,
            'scope' => 'order',
            'key' => 'in-flight-key',
            'status' => 'processing',
            'request_hash' => hash('sha256', "POST\napi/v1/dining-sessions/{$sessionId}/orders\n".json_encode($inFlightPayload)),
            'locked_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'in-flight-key')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", $inFlightPayload)
            ->assertConflict()
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('message', 'A request with this idempotency key is still processing.');

        $this->assertSame(1, Order::query()->where('dining_session_id', $sessionId)->count());
    }

    public function test_direct_service_item_skips_kitchen_and_deducts_inventory_once(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'fulfillment_mode' => 'direct', 'name' => 'Sprite', 'selling_price' => 40, 'track_inventory' => true]);
        $stock = InventoryStock::query()->create(['outlet_id' => $outlet->id, 'product_id' => $product->id, 'quantity' => 5]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'direct-service-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertCreated()
            ->assertJsonPath('data.orders.0.items.0.fulfillment_mode', 'direct')
            ->assertJsonPath('data.orders.0.items.0.status', 'ready')
            ->assertJsonPath('data.orders.0.items.0.kitchen_station_id', null);

        $item = OrderItem::query()->sole();
        $this->assertSame('3.000', $stock->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$station->id}/queue")
            ->assertOk()->assertJsonCount(0, 'data.items');

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/serve")
            ->assertOk()->assertJsonPath('data.status', 'served');
        $this->assertSame('3.000', $stock->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_kitchen_queue_limits_ready_history_to_24_hours_and_sorts_newest_first(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');

        foreach ([1, 2, 3] as $round) {
            $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', "queue-round-{$round}")->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        }
        $items = OrderItem::query()->orderBy('id')->get();
        $items[0]->update(['status' => 'ready', 'ready_at' => now()->subHours(25)]);
        $items[1]->update(['status' => 'ready', 'ready_at' => now()->subMinutes(10)]);
        $items[2]->update(['status' => 'ready', 'ready_at' => now()->subMinute()]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$station->id}/queue")
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $items[2]->id)
            ->assertJsonPath('data.items.1.id', $items[1]->id);

        $this->actingAs($owner, 'web')->getJson("/api/v1/kitchen-stations/{$station->id}/queue?status=ready&per_status=1")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $items[2]->id);
    }

    public function test_active_table_session_cannot_be_reopened_and_accepts_multiple_rounds(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 20, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertUnprocessable();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'multi-round-1')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'multi-round-2')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated();

        $this->assertSame(2, Order::query()->where('dining_session_id', $sessionId)->count());
    }

    public function test_waiter_can_cancel_pending_but_lacks_prepared_discount_and_kitchen_permissions(): void
    {
        [, , $item] = $this->ticketContext();
        $hotel = Hotel::query()->sole();
        $waiter = $this->waiter($hotel, 'waiter@example.test');

        $this->assertFalse($waiter->hasPermissionForHotel('orders.manage', $hotel));
        $this->assertTrue($waiter->hasPermissionForHotel('orders.cancel', $hotel));
        $this->assertFalse($waiter->hasPermissionForHotel('orders.cancel_prepared', $hotel));
        $this->assertFalse($waiter->hasPermissionForHotel('billing.discount', $hotel));
        $this->assertFalse($waiter->hasPermissionForHotel('kitchen.view', $hotel));
        $this->assertTrue($waiter->hasPermissionForHotel('billing.request', $hotel));
        $this->assertSame('pending', $item->status);
    }

    public function test_zero_value_session_closes_without_invoice_and_keeps_an_audit_reason(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->assertCreated()->json('data.id');

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertUnprocessable();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/close-without-sale", ['reason' => 'Guests left before ordering'])
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('dining_sessions', ['id' => $sessionId, 'status' => 'closed', 'closed_without_sale_by' => $owner->id, 'closed_without_sale_reason' => 'Guests left before ordering']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'dining_session.closed_without_sale', 'subject_id' => $sessionId]);
        $this->assertDatabaseCount('invoices', 0);
        $this->actingAs($owner, 'web')->getJson('/api/v1/tables/status')->assertOk()->assertJsonPath('data.0.display_status', 'available');
    }

    public function test_non_zero_session_cannot_be_closed_without_a_sale(): void
    {
        [$owner, $sessionId] = $this->basicSessionContext();

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/close-without-sale", ['reason' => 'Incorrect attempt'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('dining_sessions', ['id' => $sessionId, 'status' => 'occupied']);
    }

    public function test_split_payment_refund_and_reprint_are_auditable(): void
    {
        [$owner, $sessionId] = $this->basicSessionContext();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'cash-part')->postJson("/api/v1/invoices/{$invoiceId}/payments", ['method' => 'cash', 'amount' => 4])->assertOk()->assertJsonPath('data.payment_status', 'partial');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'card-part')->postJson("/api/v1/invoices/{$invoiceId}/payments", ['method' => 'card', 'amount' => 6])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $paymentId = Payment::query()->where('invoice_id', $invoiceId)->where('type', 'payment')->firstOrFail()->id;

        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/payments/{$paymentId}/refund", ['amount' => 4, 'reason' => 'Card correction'])
            ->assertOk()->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.balance_amount', '4.00');
        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/reprint")->assertOk()->assertJsonPath('data.reprint_count', 1);
    }

    public function test_invoice_cannot_be_created_twice_for_one_session(): void
    {
        [$owner, $sessionId] = $this->basicSessionContext();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertUnprocessable();
    }

    public function test_voiding_an_unpaid_invoice_closes_the_session_and_releases_the_table(): void
    {
        [$owner, $sessionId] = $this->basicSessionContext();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');

        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/void", ['reason' => 'Guest order cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $this->assertDatabaseHas('dining_sessions', ['id' => $sessionId, 'status' => 'closed']);
        $this->actingAs($owner, 'web')->getJson('/api/v1/tables/status')
            ->assertOk()
            ->assertJsonPath('data.0.display_status', 'available')
            ->assertJsonPath('data.0.active_session', null);
    }

    public function test_kitchen_quantities_are_split_into_unit_tickets_so_one_portion_can_be_cancelled(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Chicken Lollipop', 'selling_price' => 80, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'split-qty-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated();

        $items = OrderItem::query()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn (OrderItem $item) => (float) $item->quantity === 1.0 && $item->status === 'pending'));

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$items[0]->id}/cancel", ['reason' => 'Only one portion can be made'])->assertOk();
        $this->assertSame('cancelled', $items[0]->fresh()->status);
        $this->assertSame('pending', $items[1]->fresh()->status);
        $this->assertSame('80.00', DiningSession::query()->findOrFail($sessionId)->total_amount);
    }

    /** @return array{User, int, OrderItem, InventoryStock} */
    private function ticketContext(): array
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'cost_price' => 40, 'track_inventory' => true]);
        $stock = InventoryStock::query()->create(['outlet_id' => $outlet->id, 'product_id' => $product->id, 'quantity' => 5]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', "ticket-order-{$table->id}")->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        return [$owner, $sessionId, OrderItem::query()->sole(), $stock];
    }

    /** @return array{User, int} */
    private function basicSessionContext(): array
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 10, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', "billable-order-{$table->id}")->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        return [$owner, $sessionId];
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha@example.test')->sole(), Hotel::query()->sole()];
    }

    private function waiter(Hotel $hotel, string $email): User
    {
        $waiter = User::query()->create(['name' => 'Test Waiter', 'email' => $email, 'password' => 'StrongPassword1']);
        $role = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();
        $hotel->users()->attach($waiter->id, ['role_id' => $role->id, 'is_active' => true, 'joined_at' => now()]);

        return $waiter;
    }
}
