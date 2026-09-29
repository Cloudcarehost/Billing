<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Product;
use App\Models\TablePublicLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_tables_share_one_session_bill_and_free_together(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $one = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4, 'sort_order' => 2]);
        $two = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4, 'sort_order' => 3]);
        $three = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4, 'sort_order' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 100, 'track_inventory' => false]);

        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$one->id}/sessions", ['guest_count' => 2])->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/merge-tables", [
            'table_ids' => [$two->id, $three->id],
            'primary_table_id' => $one->id,
        ])->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.display_name', 'Table 2, Table 3, Table 4');

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$two->id}/sessions")->assertUnprocessable();

        $board = collect($this->actingAs($owner, 'web')->getJson('/api/v1/tables/status')->json('data'))->keyBy('name');
        $this->assertSame('occupied', $board['Table 2']['display_status']);
        $this->assertSame('occupied', $board['Table 3']['display_status']);
        $this->assertSame('occupied', $board['Table 4']['display_status']);
        $this->assertSame($sessionId, $board['Table 3']['active_session']['id']);
        $this->assertSame($one->id, $board['Table 4']['primary_table']['id']);

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'merge-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoice = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated();
        $this->assertSame('100.00', $invoice->json('data.total_amount'));
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'merge-pay')->postJson("/api/v1/invoices/{$invoice->json('data.id')}/payments", [
            'method' => 'cash', 'amount' => 100,
        ])->assertOk()->assertJsonPath('data.payment_status', 'paid');

        $after = collect($this->actingAs($owner, 'web')->getJson('/api/v1/tables/status')->json('data'))->keyBy('name');
        $this->assertSame('available', $after['Table 2']['display_status']);
        $this->assertSame('available', $after['Table 3']['display_status']);
        $this->assertSame('available', $after['Table 4']['display_status']);
    }

    public function test_merging_two_occupied_tables_combines_orders(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $one = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $two = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4]);
        $tea = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 40, 'track_inventory' => false]);
        $soda = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Soda', 'selling_price' => 60, 'track_inventory' => false]);

        $first = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$one->id}/sessions", ['guest_count' => 2])->json('data.id');
        $second = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$two->id}/sessions", ['guest_count' => 3])->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'merge-a')->postJson("/api/v1/dining-sessions/{$first}/orders", [
            'items' => [['product_id' => $tea->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'merge-b')->postJson("/api/v1/dining-sessions/{$second}/orders", [
            'items' => [['product_id' => $soda->id, 'quantity' => 1]],
        ])->assertCreated();

        $merged = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$first}/merge-tables", [
            'table_ids' => [$two->id],
            'primary_table_id' => $one->id,
        ])->assertOk();
        $this->assertSame($first, $merged->json('data.id'));
        $this->assertEquals(5, $merged->json('data.guest_count'));
        $this->assertEquals(100, (float) $merged->json('data.total_amount'));
        $names = collect($merged->json('data.orders'))->flatMap(fn ($order) => $order['items'])->pluck('item_name');
        $this->assertContains('Tea', $names);
        $this->assertContains('Soda', $names);
        $this->assertDatabaseHas('dining_sessions', ['id' => $second, 'status' => 'closed']);
    }

    public function test_parcel_tables_cannot_be_merged_and_an_extra_table_can_be_released(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $one = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $two = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$one->id}/sessions")->json('data.id');
        $parcelId = $this->actingAs($owner, 'web')->postJson('/api/v1/parcels', ['outlet_id' => $outlet->id])->assertCreated()->json('data.id');

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/merge-tables", [
            'table_ids' => [$parcelId],
        ])->assertUnprocessable();

        $otherOutlet = $hotel->outlets()->create(['name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR', 'is_active' => true]);
        $otherTable = DiningTable::query()->create(['outlet_id' => $otherOutlet->id, 'name' => 'Bar 1', 'code' => 'B1', 'capacity' => 4]);
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/merge-tables", [
            'table_ids' => [$otherTable->id],
        ])->assertUnprocessable();

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/merge-tables", [
            'table_ids' => [$two->id],
            'primary_table_id' => $one->id,
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/unmerge-table", [
            'table_id' => $one->id,
        ])->assertUnprocessable();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/unmerge-table", [
            'table_id' => $two->id,
        ])->assertOk();

        $board = collect($this->actingAs($owner, 'web')->getJson('/api/v1/tables/status')->json('data'))->keyBy('name');
        $this->assertSame('available', $board['Table 3']['display_status']);
        $this->assertSame('occupied', $board['Table 2']['display_status']);

        $token = TablePublicLink::query()->where('dining_table_id', $two->id)->value('token_encrypted');
        if ($token) {
            $this->getJson("/api/v1/public/tables/{$token}/status")->assertOk()->assertJsonPath('data.active_order', null);
        }
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
