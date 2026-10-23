<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_session_moves_to_an_available_table_without_changing_its_bill_or_timer(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $source = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 7', 'code' => 'T7', 'capacity' => 4, 'waiter_called_at' => now()]);
        $target = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 12', 'code' => 'T12', 'capacity' => 6]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 80, 'track_inventory' => false]);

        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$source->id}/sessions", ['guest_count' => 3])->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'shift-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $before = DiningSession::query()->findOrFail($sessionId);

        $response = $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'shift-table-7-12')->postJson("/api/v1/dining-sessions/{$sessionId}/shift-table", [
            'source_table_id' => $source->id,
            'target_table_id' => $target->id,
        ])->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.dining_table_id', $target->id)
            ->assertJsonPath('data.display_name', 'Table 12')
            ->assertJsonPath('data.status', 'pending_bill')
            ->assertJsonPath('data.guest_count', 3)
            ->assertJsonPath('data.total_amount', '160.00')
            ->assertJsonCount(1, 'data.orders');

        $after = DiningSession::query()->findOrFail($sessionId);
        $this->assertSame($before->opened_at->timestamp, $after->opened_at->timestamp);
        $this->assertSame($before->waiter_id, $after->waiter_id);
        $this->assertNull($source->fresh()->waiter_called_at);
        $this->assertDatabaseHas('activity_logs', [
            'subject_id' => $sessionId,
            'action' => 'dining_session.table_shifted',
            'user_id' => $owner->id,
        ]);
        $audit = ActivityLog::query()->where('action', 'dining_session.table_shifted')->sole();
        $this->assertSame($source->id, $audit->properties['source_table_id']);
        $this->assertSame($target->id, $audit->properties['target_table_id']);

        $board = collect($this->actingAs($owner, 'web')->getJson("/api/v1/tables/status?outlet_id={$outlet->id}")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('available', $board[$source->id]['display_status']);
        $this->assertNull($board[$source->id]['active_session']);
        $this->assertSame('pending_bill', $board[$target->id]['display_status']);
        $this->assertSame($sessionId, $board[$target->id]['active_session']['id']);

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'shift-table-7-12')->postJson("/api/v1/dining-sessions/{$sessionId}/shift-table", [
            'source_table_id' => $source->id,
            'target_table_id' => $target->id,
        ])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.dining_table_id', $target->id);
    }

    public function test_primary_and_joined_tables_can_each_be_replaced_without_breaking_the_group(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $one = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $two = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $three = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4]);
        $four = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$one->id}/sessions")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/merge-tables", [
            'table_ids' => [$two->id],
            'primary_table_id' => $one->id,
        ])->assertOk();

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'shift-primary')->postJson("/api/v1/dining-sessions/{$sessionId}/shift-table", [
            'source_table_id' => $one->id,
            'target_table_id' => $three->id,
        ])->assertOk()
            ->assertJsonPath('data.dining_table_id', $three->id)
            ->assertJsonPath('data.display_name', 'Table 3, Table 2');
        $this->assertEqualsCanonicalizing([$two->id, $three->id], DiningSession::query()->findOrFail($sessionId)->memberTableIds());

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'shift-joined')->postJson("/api/v1/dining-sessions/{$sessionId}/shift-table", [
            'source_table_id' => $two->id,
            'target_table_id' => $four->id,
        ])->assertOk()
            ->assertJsonPath('data.dining_table_id', $three->id)
            ->assertJsonPath('data.display_name', 'Table 3, Table 4');
        $this->assertEqualsCanonicalizing([$three->id, $four->id], DiningSession::query()->findOrFail($sessionId)->memberTableIds());

        $board = collect($this->actingAs($owner, 'web')->getJson("/api/v1/tables/status?outlet_id={$outlet->id}")->json('data'))->keyBy('id');
        $this->assertSame('available', $board[$one->id]['display_status']);
        $this->assertSame('available', $board[$two->id]['display_status']);
        $this->assertSame('occupied', $board[$three->id]['display_status']);
        $this->assertSame('occupied', $board[$four->id]['display_status']);
    }

    public function test_shift_rejects_unavailable_invalid_and_invoiced_destinations(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $source = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Source', 'code' => 'SOURCE', 'capacity' => 4]);
        $occupied = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Occupied', 'code' => 'OCC', 'capacity' => 4]);
        $inactive = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Inactive', 'code' => 'OFF', 'capacity' => 4, 'is_active' => false]);
        $parcel = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Parcel', 'code' => 'P1', 'capacity' => 1, 'service_type' => 'parcel']);
        $otherOutlet = $hotel->outlets()->create(['name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR', 'is_active' => true]);
        $crossOutlet = DiningTable::query()->create(['outlet_id' => $otherOutlet->id, 'name' => 'Bar 1', 'code' => 'B1', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$source->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$occupied->id}/sessions")->assertCreated();

        foreach ([$occupied, $inactive, $parcel, $crossOutlet] as $index => $target) {
            $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', "invalid-shift-{$index}")->postJson("/api/v1/dining-sessions/{$sessionId}/shift-table", [
                'source_table_id' => $source->id,
                'target_table_id' => $target->id,
            ])->assertUnprocessable();
        }

        $validTarget = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Valid', 'code' => 'VALID', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 50, 'track_inventory' => false]);
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'invoice-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'invoiced-shift')->postJson("/api/v1/dining-sessions/{$sessionId}/shift-table", [
            'source_table_id' => $source->id,
            'target_table_id' => $validTarget->id,
        ])->assertUnprocessable();

        $this->assertSame($source->id, DiningSession::query()->findOrFail($sessionId)->dining_table_id);
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha-shift@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha-shift@example.test')->sole(), Hotel::query()->sole()];
    }
}
