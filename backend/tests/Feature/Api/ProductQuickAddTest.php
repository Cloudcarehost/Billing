<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductQuickAddTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_pin_round_trips_and_search_includes_the_flag(): void
    {
        [$owner, $hotel] = $this->ownerContext();

        $id = $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
            'name' => 'Water bottle', 'selling_price' => 20, 'is_quick' => true,
        ])->assertCreated()->assertJsonPath('data.is_quick', true)->json('data.id');

        $this->actingAs($owner, 'web')->getJson('/api/v1/products/search?limit=500')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.is_quick', true);

        $this->actingAs($owner, 'web')->putJson("/api/v1/products/{$id}", ['is_quick' => false])
            ->assertOk()
            ->assertJsonPath('data.is_quick', false);

        $this->assertFalse(Product::query()->where('hotel_id', $hotel->id)->sole()->is_quick);
    }

    public function test_thirteenth_pin_is_rejected(): void
    {
        [$owner] = $this->ownerContext();

        for ($index = 1; $index <= 12; $index++) {
            $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
                'name' => "Pin {$index}", 'selling_price' => 10, 'is_quick' => true,
            ])->assertCreated();
        }

        $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
            'name' => 'Pin 13', 'selling_price' => 10, 'is_quick' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_quick');

        $this->assertSame(12, Product::query()->where('is_quick', true)->count());
    }

    public function test_popular_ranks_by_quantity_for_the_requested_outlet_and_ignores_voided_bills(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $restaurant = $hotel->outlets()->sole();
        $bar = Outlet::query()->create(['hotel_id' => $hotel->id, 'name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR']);
        $water = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Water', 'selling_price' => 20]);
        $papad = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Papad', 'selling_price' => 30]);
        $chicken = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Chicken fry', 'selling_price' => 180]);
        $voidedItem = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Voided feast', 'selling_price' => 10]);
        $t1 = DiningTable::query()->create(['outlet_id' => $restaurant->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $t2 = DiningTable::query()->create(['outlet_id' => $restaurant->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $barTable = DiningTable::query()->create(['outlet_id' => $bar->id, 'name' => 'Bar 1', 'code' => 'B1', 'capacity' => 2]);

        $this->bill($owner, $t1->id, [
            ['product_id' => $water->id, 'quantity' => 5],
            ['product_id' => $papad->id, 'quantity' => 2],
        ], 'quick-restaurant');
        $this->bill($owner, $barTable->id, [['product_id' => $chicken->id, 'quantity' => 10]], 'quick-bar');
        $voidedId = $this->bill($owner, $t2->id, [['product_id' => $voidedItem->id, 'quantity' => 40]], 'quick-void');
        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$voidedId}/void", ['reason' => 'Test void'])->assertOk();

        $restaurantPopular = $this->actingAs($owner, 'web')->getJson("/api/v1/products/popular?outlet_id={$restaurant->id}")
            ->assertOk()
            ->json('data');
        $this->assertSame([$water->id, $papad->id], array_column($restaurantPopular, 'product_id'));
        $this->assertNotContains($chicken->id, array_column($restaurantPopular, 'product_id'));
        $this->assertNotContains($voidedItem->id, array_column($restaurantPopular, 'product_id'));

        $this->actingAs($owner, 'web')->getJson("/api/v1/products/popular?outlet_id={$bar->id}")
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $chicken->id)
            ->assertJsonCount(1, 'data');
    }

    /** @param list<array{product_id: int, quantity: int}> $items */
    private function bill(User $owner, int $tableId, array $items, string $key): int
    {
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$tableId}/sessions", ['guest_count' => 2])->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', $key)->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => $items])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();

        return $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');
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
}
