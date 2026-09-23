<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutletScopedBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_is_lightweight_and_restricted_users_cannot_cross_outlets(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $firstOutlet = $hotel->outlets()->sole();
        $secondOutlet = Outlet::query()->create(['hotel_id' => $hotel->id, 'name' => 'Rooftop', 'code' => 'ROOF', 'invoice_prefix' => 'ROOF']);
        $firstTable = DiningTable::query()->create(['outlet_id' => $firstOutlet->id, 'name' => 'Table 1', 'code' => 'T1']);
        DiningTable::query()->create(['outlet_id' => $secondOutlet->id, 'name' => 'Table 2', 'code' => 'T2']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 20, 'track_inventory' => false, 'fulfillment_mode' => 'kitchen']);
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/v1/tables/{$firstTable->id}/sessions")->assertCreated()->json('data.id');
        $this->withHeader('Idempotency-Key', 'outlet-board-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1, 'kitchen_note' => 'No sugar']]])->assertCreated();

        $waiterRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();
        $waiter = app(HotelMembershipService::class)->create($hotel, [
            'name' => 'Scoped Waiter', 'email' => 'scoped@example.test', 'password' => 'StrongPassword1',
            'role_id' => $waiterRole->id, 'is_active' => true, 'outlet_ids' => [$firstOutlet->id],
        ]);

        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($waiter);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('X-Outlet-Id', (string) $firstOutlet->id)->getJson('/api/v1/tables/status')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.active_session.active_item_count', 1)
            ->assertJsonCount(1, 'data.0.active_session.preview_items')->assertJsonMissingPath('data.0.active_session.orders.0.items.0.kitchen_note');
        $this->withHeader('X-Outlet-Id', (string) $secondOutlet->id)->getJson('/api/v1/tables/status')->assertForbidden();
        $this->withHeader('X-Outlet-Id', (string) $firstOutlet->id)->getJson("/api/v1/dining-sessions/{$sessionId}")
            ->assertOk()->assertJsonPath('data.orders.0.items.0.kitchen_note', 'No sugar')->assertJsonPath('data.status_history.1.status', 'order_sent');
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main', 'outlet_code' => 'MAIN',
            'owner_name' => 'Owner', 'owner_email' => 'owner@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->sole(), Hotel::query()->sole()];
    }
}
