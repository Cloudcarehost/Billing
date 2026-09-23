<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiter_can_view_and_open_tables_but_cannot_configure_them(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $waiter = $this->member($hotel, 'waiter', 'waiter@example.test');

        $this->asUser($waiter)->getJson('/api/v1/tables/status')->assertOk()->assertJsonPath('data.0.name', 'Table 4');
        $this->asUser($waiter)->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated();
        $this->asUser($waiter)->postJson('/api/v1/tables', [
            'outlet_id' => $outlet->id, 'name' => 'Table 9', 'code' => 'T9', 'capacity' => 2,
        ])->assertForbidden();
        $this->asUser($waiter)->patchJson("/api/v1/tables/{$table->id}", ['name' => 'VIP 4'])->assertForbidden();
        $this->asUser($owner)->patchJson("/api/v1/tables/{$table->id}", ['name' => 'VIP 4'])
            ->assertOk()->assertJsonPath('data.name', 'VIP 4');
    }

    public function test_waiter_cannot_assign_another_waiter_without_sessions_assign(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $waiter = $this->member($hotel, 'waiter', 'waiter@example.test');
        $other = $this->member($hotel, 'waiter', 'other-waiter@example.test');

        $this->asUser($waiter)->postJson("/api/v1/tables/{$table->id}/sessions", ['waiter_id' => $other->id, 'guest_count' => 2])->assertForbidden();
        $sessionId = $this->asUser($owner)->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated()->json('data.id');
        $this->asUser($waiter)->postJson("/api/v1/dining-sessions/{$sessionId}/assign-waiter", ['waiter_id' => $other->id])->assertForbidden();
        $this->asUser($owner)->getJson('/api/v1/dining-staff')->assertOk()->assertJsonFragment(['id' => $waiter->id, 'name' => $waiter->name]);
        $this->asUser($owner)->postJson("/api/v1/dining-sessions/{$sessionId}/assign-waiter", ['waiter_id' => $waiter->id])
            ->assertOk()->assertJsonPath('data.waiter_id', $waiter->id);
    }

    public function test_waiter_can_cancel_pending_and_direct_items_but_not_prepared_kitchen_items(): void
    {
        [$owner, $hotel, $sessionId, $pendingItem, $directItem] = $this->mixedOrderContext();
        $waiter = $this->member($hotel, 'waiter', 'waiter@example.test');
        $kitchen = $this->member($hotel, 'kitchen', 'kitchen@example.test');

        $this->asUser($waiter)->postJson("/api/v1/order-items/{$pendingItem->id}/cancel", ['reason' => 'Guest changed their mind'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->asUser($waiter)->postJson("/api/v1/order-items/{$directItem->id}/cancel", ['reason' => 'Wrong bottle'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $preparing = $this->kitchenItem($owner, $hotel, $sessionId);
        $this->asUser($owner)->postJson("/api/v1/order-items/{$preparing->id}/kitchen-status", ['status' => 'preparing'])->assertOk();
        $this->asUser($waiter)->postJson("/api/v1/order-items/{$preparing->id}/cancel", ['reason' => 'Too late'])
            ->assertForbidden();
        $this->asUser($kitchen)->postJson("/api/v1/order-items/{$preparing->id}/cancel", ['reason' => 'Kitchen mistake'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_high_value_and_closed_session_cancellations_are_rejected(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Platter', 'selling_price' => 1000, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->asUser($owner)->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->asUser($owner)->withHeader('Idempotency-Key', 'high-value-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $item = OrderItem::query()->latest('id')->firstOrFail();
        $waiter = $this->member($hotel, 'waiter', 'waiter@example.test');

        $this->asUser($waiter)->postJson("/api/v1/order-items/{$item->id}/cancel", ['reason' => 'Too expensive'])
            ->assertForbidden();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$item->id}/cancel", ['reason' => 'Owner approved'])->assertOk();

        $second = $this->kitchenItem($owner, $hotel, $sessionId, 'second-kitchen-item');
        $this->asUser($owner)->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$second->id}/cancel", ['reason' => 'After bill request'])
            ->assertUnprocessable();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$second->id}/kitchen-status", ['status' => 'preparing'])->assertOk();
    }

    public function test_kitchen_and_serve_are_blocked_after_an_invoice_exists(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->asUser($owner)->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->asUser($owner)->withHeader('Idempotency-Key', 'invoice-block-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $item = OrderItem::query()->sole();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'preparing'])->assertOk();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'ready'])->assertOk();
        $this->asUser($owner)->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $this->asUser($owner)->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$item->id}/serve")->assertUnprocessable();
        $this->asUser($owner)->postJson("/api/v1/order-items/{$item->id}/cancel", ['reason' => 'Too late'])->assertUnprocessable();
    }

    /** @return array{User, Hotel, int, OrderItem, OrderItem} */
    private function mixedOrderContext(): array
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $kitchenProduct = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Lollipop', 'selling_price' => 80, 'track_inventory' => false]);
        $directProduct = Product::query()->create(['hotel_id' => $hotel->id, 'fulfillment_mode' => 'direct', 'name' => 'Sprite', 'selling_price' => 40, 'track_inventory' => true]);
        InventoryStock::query()->create(['outlet_id' => $outlet->id, 'product_id' => $directProduct->id, 'quantity' => 10]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $sessionId = $this->asUser($owner)->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->asUser($owner)->withHeader('Idempotency-Key', 'mixed-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [
                ['product_id' => $kitchenProduct->id, 'quantity' => 1],
                ['product_id' => $directProduct->id, 'quantity' => 2],
            ],
        ])->assertCreated();
        $pending = OrderItem::query()->where('item_name', 'Lollipop')->sole();
        $direct = OrderItem::query()->where('item_name', 'Sprite')->sole();

        return [$owner, $hotel, $sessionId, $pending, $direct];
    }

    private function kitchenItem(User $owner, Hotel $hotel, int $sessionId, string $key = 'extra-kitchen-item'): OrderItem
    {
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Paneer', 'selling_price' => 90, 'track_inventory' => false]);
        $this->asUser($owner)->withHeader('Idempotency-Key', $key)->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        return OrderItem::query()->where('item_name', 'Paneer')->latest('id')->firstOrFail();
    }

    private function member(Hotel $hotel, string $slug, string $email): User
    {
        $role = Role::query()->where('hotel_id', $hotel->id)->where('slug', $slug)->sole();

        return app(HotelMembershipService::class)->create($hotel, [
            'name' => ucfirst($slug).' User',
            'email' => $email,
            'password' => 'StrongPassword1',
            'role_id' => $role->id,
            'is_active' => true,
            'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
        ]);
    }

    private function asUser(User $user): static
    {
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this->withHeader('Origin', 'http://localhost:5173');
    }
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha@example.test')->sole(), Hotel::query()->sole()];
    }
}
