<?php

namespace Tests\Feature\Api;

use App\Enums\FulfillmentMode;
use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_can_be_created_without_customer_details_and_filled_in_later(): void
    {
        [$owner, $hotel, $sessionId] = $this->pendingBill();

        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")
            ->assertCreated()
            ->assertJsonPath('data.customer_id', null)
            ->assertJsonPath('data.customer_name', null)
            ->json('data.id');

        $this->actingAs($owner, 'web')->patchJson("/api/v1/invoices/{$invoiceId}/guest", [
            'customer_name' => 'Walk-in guest',
            'customer_phone' => '9999999999',
            'customer_email' => 'guest@example.test',
            'customer_gstin' => '27AAAAA0000A1Z5',
        ])->assertOk()->assertJsonPath('data.customer_name', 'Walk-in guest')->assertJsonPath('data.customer_gstin', '27AAAAA0000A1Z5');
    }

    public function test_optional_guest_fields_are_snapshotted_when_provided_at_invoice_time(): void
    {
        [$owner, $hotel, $sessionId] = $this->pendingBill();

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice", [
            'customer_name' => 'Priya',
            'customer_phone' => '8888888888',
        ])->assertCreated()->assertJsonPath('data.customer_name', 'Priya')->assertJsonPath('data.customer_email', null);
    }

    public function test_invoice_items_snapshot_catalog_fields_so_renames_do_not_rewrite_history(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $category = Category::query()->create(['hotel_id' => $hotel->id, 'name' => 'Mains', 'slug' => 'mains']);
        $product = Product::query()->create([
            'hotel_id' => $hotel->id, 'category_id' => $category->id, 'name' => 'Paneer makhani',
            'selling_price' => 180, 'cost_price' => 70, 'tax_rate' => 5, 'hsn_code' => '2106',
            'serving_size' => '250 g', 'fulfillment_mode' => FulfillmentMode::Direct->value, 'track_inventory' => false,
        ]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'history-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill");
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoiceId,
            'item_name' => 'Paneer makhani',
            'category_name' => 'Mains',
            'hsn_code' => '2106',
            'serving_size' => '250 g',
            'tax_type' => TaxType::Exclusive->value,
            'unit_price' => '180.00',
            'unit_cost' => '70.00',
        ]);

        $category->update(['name' => 'Renamed starters']);
        $product->update(['name' => 'New paneer name', 'hsn_code' => '9999']);

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoiceId,
            'item_name' => 'Paneer makhani',
            'category_name' => 'Mains',
            'hsn_code' => '2106',
        ]);
        $this->assertSame(OrderItemStatus::Ready->value, $hotel->diningSessions()->first()->orders()->first()->items()->first()->status);
    }

    public function test_invoice_item_survives_attempted_invoice_delete(): void
    {
        [$owner, $hotel, $sessionId] = $this->pendingBill();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->json('data.id');

        try {
            Invoice::query()->findOrFail($invoiceId)->delete();
            $this->fail('Invoice delete should be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => InvoiceStatus::Issued->value]);
            $this->assertGreaterThan(0, InvoiceItem::query()->where('invoice_id', $invoiceId)->count());
        }
    }

    /** @return array{User, Hotel, int} */
    private function pendingBill(): array
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 40, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 1])->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'history-pending-'.$table->id)->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();

        return [$owner, $hotel, $sessionId];
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
