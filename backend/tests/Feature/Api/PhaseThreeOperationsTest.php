<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThreeOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_transfer_and_wastage_create_traceable_stock_movements(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $fromOutlet = $hotel->outlets()->sole();
        $toOutlet = $hotel->outlets()->create(['name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Mineral Water', 'selling_price' => 30, 'cost_price' => 12, 'track_inventory' => true]);

        $this->actingAs($owner, 'web')->postJson('/api/v1/inventory/receipts', [
            'outlet_id' => $fromOutlet->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 12]],
        ])->assertCreated();
        $this->assertSame('10.000', $this->stock($fromOutlet->id, $product->id)->quantity);

        $transferId = $this->actingAs($owner, 'web')->postJson('/api/v1/inventory/transfers', [
            'from_outlet_id' => $fromOutlet->id,
            'to_outlet_id' => $toOutlet->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated()->json('data.id');
        $this->assertSame('7.000', $this->stock($fromOutlet->id, $product->id)->quantity);

        $this->actingAs($owner, 'web')->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
            ->assertOk();
        $this->assertSame('3.000', $this->stock($toOutlet->id, $product->id)->quantity);

        $this->actingAs($owner, 'web')->postJson('/api/v1/inventory/wastage', [
            'outlet_id' => $toOutlet->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Damaged bottle',
        ])->assertCreated();

        $this->assertSame('2.000', $this->stock($toOutlet->id, $product->id)->quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => 'stock_receipt']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'transfer_out']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'transfer_in']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'wastage']);
    }

    public function test_physical_count_requires_approval_before_stock_is_adjusted(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Rice', 'selling_price' => 100, 'track_inventory' => true]);
        InventoryStock::query()->create(['outlet_id' => $outlet->id, 'product_id' => $product->id, 'quantity' => 8, 'average_cost' => 50]);

        $countId = $this->actingAs($owner, 'web')->postJson('/api/v1/inventory/counts', [
            'outlet_id' => $outlet->id,
            'items' => [['product_id' => $product->id, 'counted_quantity' => 5]],
        ])->assertCreated()->json('data.id');
        $this->assertSame('8.000', $this->stock($outlet->id, $product->id)->quantity);

        $this->actingAs($owner, 'web')->postJson("/api/v1/inventory/counts/{$countId}/approve")
            ->assertOk();
        $this->assertSame('5.000', $this->stock($outlet->id, $product->id)->quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => 'stock_count_adjustment', 'quantity_delta' => '-3.000']);
    }

    public function test_dashboard_and_invoice_register_use_server_business_data_without_customer_information(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 20, 'track_inventory' => false]);

        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])
            ->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'phase-three-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'customer_id' => null]);
        $businessDate = Invoice::query()->findOrFail($invoiceId)->business_date->toDateString();
        $reportResponse = $this->actingAs($owner, 'web')->getJson("/api/v1/reports/invoices?from={$businessDate}&to={$businessDate}");
        $reportResponse->assertOk()->assertJsonCount(1, 'data');
        $dashboardResponse = $this->actingAs($owner, 'web')->getJson("/api/v1/dashboard?from={$businessDate}&to={$businessDate}");
        $dashboardResponse->assertOk()->assertJsonPath('data.sales.bills', 1);
        $this->assertSame(20.0, (float) $dashboardResponse->json('data.sales.amount'));
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel',
            'outlet_name' => 'Main Restaurant',
            'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner',
            'owner_email' => 'asha@example.test',
            'password' => 'StrongPassword1',
            'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha@example.test')->sole(), Hotel::query()->sole()];
    }

    private function stock(int $outletId, int $productId): InventoryStock
    {
        return InventoryStock::query()->where('outlet_id', $outletId)->where('product_id', $productId)->sole();
    }
}
