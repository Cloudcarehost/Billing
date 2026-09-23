<?php

namespace Tests\Feature\Api;

use App\Models\Hotel;
use App\Models\Product;
use App\Models\TablePublicLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTableStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_qr_exposes_only_the_current_guest_safe_status_and_supports_etags(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $tableId = $this->actingAs($owner, 'web')->postJson('/api/v1/tables', [
            'outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4,
        ])->assertCreated()->json('data.id');
        $token = TablePublicLink::query()->sole()->token_encrypted;
        $product = Product::query()->create([
            'hotel_id' => $hotel->id, 'name' => 'Sprite', 'selling_price' => 40,
            'cost_price' => 20, 'track_inventory' => false, 'fulfillment_mode' => 'direct',
        ]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$tableId}/sessions")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'public-table-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'kitchen_note' => 'Private note']],
        ])->assertCreated();

        $response = $this->getJson("/api/v1/public/tables/{$token}/status")
            ->assertOk()
            ->assertJsonPath('data.hotel_name', 'Lakeview Hotel')
            ->assertJsonPath('data.table_name', 'Table 4')
            ->assertJsonPath('data.active_order.items.0.name', 'Sprite')
            ->assertJsonPath('data.active_order.items.0.quantity', '2.000')
            ->assertJsonPath('data.active_order.items.0.status', 'ready')
            ->assertJsonMissingPath('data.active_order.items.0.id')
            ->assertJsonMissingPath('data.active_order.items.0.unit_cost')
            ->assertJsonMissingPath('data.active_order.items.0.kitchen_note')
            ->assertJsonMissingPath('data.waiter');

        $this->withHeader('If-None-Match', $response->headers->get('ETag'))
            ->getJson("/api/v1/public/tables/{$token}/status")->assertStatus(304);
    }

    public function test_rotating_or_disabling_a_qr_invalidates_public_access(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $tableId = $this->actingAs($owner, 'web')->postJson('/api/v1/tables', [
            'outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4,
        ])->assertCreated()->json('data.id');
        $oldToken = TablePublicLink::query()->sole()->token_encrypted;

        $newToken = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$tableId}/public-link/rotate")
            ->assertOk()->json('data.token');
        $this->getJson("/api/v1/public/tables/{$oldToken}/status")->assertNotFound();
        $this->getJson("/api/v1/public/tables/{$newToken}/status")->assertOk();

        $this->actingAs($owner, 'web')->patchJson("/api/v1/tables/{$tableId}/public-link", ['is_active' => false])->assertOk();
        $this->getJson("/api/v1/public/tables/{$newToken}/status")->assertNotFound();
    }

    public function test_guest_can_call_the_waiter_and_staff_can_clear_the_call(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $tableId = $this->actingAs($owner, 'web')->postJson('/api/v1/tables', [
            'outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4,
        ])->assertCreated()->json('data.id');
        $token = TablePublicLink::query()->sole()->token_encrypted;

        $this->postJson("/api/v1/public/tables/{$token}/call-waiter")
            ->assertOk()
            ->assertJsonPath('data.waiter_called', true);
        $this->getJson("/api/v1/public/tables/{$token}/status")->assertOk()->assertJsonPath('data.waiter_called', true);
        $this->actingAs($owner, 'web')->getJson('/api/v1/tables/status')
            ->assertOk()
            ->assertJsonPath('data.0.waiter_called', true);

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$tableId}/acknowledge-waiter-call")->assertOk();
        $this->getJson("/api/v1/public/tables/{$token}/status")->assertOk()->assertJsonPath('data.waiter_called', false);
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->sole(), Hotel::query()->sole()];
    }
}
