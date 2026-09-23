<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\InvoiceSequence;
use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAndFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_is_optional_and_never_blocks_orders(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $untracked = $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
            'name' => 'Tea', 'selling_price' => 20, 'fulfillment_mode' => 'direct',
        ])->assertCreated()->json('data');
        $this->assertFalse($untracked['track_inventory']);
        $this->assertDatabaseMissing('inventory_stocks', ['product_id' => $untracked['id']]);

        $tracked = $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
            'name' => 'Sprite', 'selling_price' => 40, 'fulfillment_mode' => 'direct', 'track_inventory' => true,
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('inventory_stocks', ['outlet_id' => $outlet->id, 'product_id' => $tracked['id'], 'quantity' => '0.000']);

        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'optional-stock-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [
                ['product_id' => $untracked['id'], 'quantity' => 1],
                ['product_id' => $tracked['id'], 'quantity' => 2],
            ],
        ])->assertCreated();
        $this->assertSame('-2.000', InventoryStock::query()->where('product_id', $tracked['id'])->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_prepared_cancellation_records_wastage_instead_of_returning_stock(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => true]);
        InventoryStock::query()->create(['outlet_id' => $outlet->id, 'product_id' => $product->id, 'quantity' => 5]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'wastage-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $item = OrderItem::query()->sole();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'preparing'])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/cancel", ['reason' => 'Dropped tray'])->assertOk();

        $this->assertSame('4.000', InventoryStock::query()->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_wastages', ['product_id' => $product->id, 'quantity' => '1.000', 'reason' => 'Prepared item cancelled']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'wastage', 'reference_id' => $item->id]);
    }

    public function test_recipe_cycles_are_rejected(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $sauce = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Sauce', 'selling_price' => 10, 'track_inventory' => true]);
        $curry = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => true]);
        $this->actingAs($owner, 'web')->postJson('/api/v1/recipes', [
            'product_id' => $curry->id, 'yield_quantity' => 1, 'items' => [['ingredient_product_id' => $sauce->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson('/api/v1/recipes', [
            'product_id' => $sauce->id, 'yield_quantity' => 1, 'items' => [['ingredient_product_id' => $curry->id, 'quantity' => 1]],
        ])->assertStatus(422);
        $this->actingAs($owner, 'web')->postJson('/api/v1/recipes', [
            'product_id' => $curry->id, 'items' => [['ingredient_product_id' => $curry->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_refunds_cannot_exceed_the_remaining_payment_balance(): void
    {
        [$owner, $sessionId] = $this->billableSession();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'full-pay')->postJson("/api/v1/invoices/{$invoiceId}/payments", ['method' => 'cash', 'amount' => 10])->assertOk();
        $paymentId = Payment::query()->where('invoice_id', $invoiceId)->where('type', 'payment')->value('id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/payments/{$paymentId}/refund", ['amount' => 6, 'reason' => 'Partial return'])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/payments/{$paymentId}/refund", ['amount' => 5, 'reason' => 'Too much'])->assertUnprocessable();
        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/payments/{$paymentId}/refund", ['amount' => 4, 'reason' => 'Remainder'])->assertOk();
    }

    public function test_invoice_sequence_insert_or_ignore_does_not_reset_an_existing_period(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        InvoiceSequence::query()->create(['outlet_id' => $outlet->id, 'period' => '209901', 'next_number' => 9]);
        $first = app(InvoiceSequenceService::class)->nextNumber($outlet->id, '209901');
        $second = app(InvoiceSequenceService::class)->nextNumber($outlet->id, '209901');
        $this->assertSame([9, 10], [$first, $second]);
        $this->assertSame(1, InvoiceSequence::query()->where('outlet_id', $outlet->id)->where('period', '209901')->count());

        $numbers = [];
        foreach (range(1, 5) as $index) {
            $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => "Seq {$index}", 'code' => "S{$index}", 'capacity' => 2]);
            $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => "Item {$index}", 'selling_price' => 10, 'track_inventory' => false]);
            $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
            $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', "seq-order-{$index}")->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
            $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill");
            $numbers[] = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->json('data.invoice_number');
        }
        $this->assertCount(5, collect($numbers)->unique());
    }

    public function test_concurrent_sequence_connections_do_not_share_the_same_number(): void
    {
        [, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $period = '209902';
        $numbers = [];
        foreach (range(1, 8) as $ignored) {
            $numbers[] = app(InvoiceSequenceService::class)->nextNumber($outlet->id, $period);
        }
        $this->assertSame(range(1, 8), $numbers);
        $this->assertCount(8, collect($numbers)->unique());
        $this->assertSame(1, InvoiceSequence::query()->where('outlet_id', $outlet->id)->where('period', $period)->count());
    }

    public function test_hotel_inventory_policy_can_be_updated(): void
    {
        [$owner] = $this->ownerContext();
        $this->actingAs($owner, 'web')->patchJson('/api/v1/hotel', [
            'allow_negative_stock' => true,
            'inventory_deduction_rule' => 'served',
        ])->assertOk()->assertJsonPath('data.allow_negative_stock', true)->assertJsonPath('data.inventory_deduction_rule', 'served');
    }

    /** @return array{User, int} */
    private function billableSession(): array
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 10, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'finance-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

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
}
