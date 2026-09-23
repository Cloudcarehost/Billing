<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAndBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_category_must_belong_to_the_current_hotel(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $otherHotel = Hotel::query()->create(['name' => 'Other Hotel', 'slug' => 'other-hotel']);
        $otherCategory = Category::query()->create(['hotel_id' => $otherHotel->id, 'name' => 'Other', 'slug' => 'other']);

        $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
            'name' => 'Tea', 'selling_price' => 20, 'category_id' => $otherCategory->id,
        ])->assertNotFound();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_table_cannot_have_two_active_sessions(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $table = DiningTable::query()->create(['outlet_id' => $hotel->outlets()->sole()->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertUnprocessable();
    }

    public function test_server_calculates_order_and_invoice_totals_then_closes_after_payment(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 100, 'cost_price' => 40, 'tax_rate' => 5, 'track_inventory' => false]);

        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'catalog-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertCreated()->assertJsonPath('data.total_amount', '210.00');
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'catalog-payment')->postJson("/api/v1/invoices/{$invoiceId}/payments", ['method' => 'cash', 'amount' => 210])->assertOk()->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_owner_can_update_an_outlet_from_settings(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();

        $this->actingAs($owner, 'web')->putJson("/api/v1/outlets/{$outlet->id}", [
            'name' => 'Garden Restaurant',
            'code' => 'GARDEN',
            'invoice_prefix' => 'GDN',
            'address' => 'Lobby level',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Garden Restaurant')
            ->assertJsonPath('data.code', 'GARDEN')
            ->assertJsonPath('data.invoice_prefix', 'GDN');

        $this->assertDatabaseHas('outlets', [
            'id' => $outlet->id,
            'name' => 'Garden Restaurant',
            'code' => 'GARDEN',
            'invoice_prefix' => 'GDN',
            'address' => 'Lobby level',
        ]);
    }

    public function test_invoice_numbers_are_unique_for_successive_bills(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 10, 'track_inventory' => false]);
        $numbers = collect([1, 2])->map(function (int $number) use ($owner, $outlet, $product) {
            $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => "Table {$number}", 'code' => "T{$number}", 'capacity' => 4]);
            $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
            $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', "invoice-sequence-{$table->id}")->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
            $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill");

            return $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->json('data.invoice_number');
        });
        $this->assertCount(2, $numbers->unique());
    }

    public function test_owner_can_update_and_delete_a_category(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $category = Category::query()->create(['hotel_id' => $hotel->id, 'name' => 'Starters', 'slug' => 'starters']);
        $product = Product::query()->create([
            'hotel_id' => $hotel->id, 'category_id' => $category->id, 'name' => 'Soup',
            'selling_price' => 80, 'track_inventory' => false,
        ]);

        $this->actingAs($owner, 'web')->putJson("/api/v1/categories/{$category->id}", [
            'name' => 'Hot starters',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Hot starters');

        $this->actingAs($owner, 'web')->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_owner_cannot_delete_the_last_outlet(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();

        $this->actingAs($owner, 'web')->deleteJson("/api/v1/outlets/{$outlet->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outlet');

        $this->assertDatabaseHas('outlets', ['id' => $outlet->id]);
    }

    public function test_owner_can_delete_an_empty_outlet_but_not_one_with_history(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $main = $hotel->outlets()->sole();
        DiningTable::query()->create(['outlet_id' => $main->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);

        $barId = $this->actingAs($owner, 'web')->postJson('/api/v1/outlets', [
            'name' => 'Bar Counter',
            'code' => 'BAR',
            'invoice_prefix' => 'BAR',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$hotel->outlets()->findOrFail($main->id)->diningTables()->value('id')}/sessions", [
            'guest_count' => 2,
        ])->assertCreated();

        $this->actingAs($owner, 'web')->deleteJson("/api/v1/outlets/{$main->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outlet');

        $this->actingAs($owner, 'web')->deleteJson("/api/v1/outlets/{$barId}")
            ->assertOk();

        $this->assertDatabaseMissing('outlets', ['id' => $barId]);
        $this->assertDatabaseHas('outlets', ['id' => $main->id]);
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
