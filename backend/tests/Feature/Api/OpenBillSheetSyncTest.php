<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncOpenBillsToGoogleSheet;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\GoogleSheetsWriter;
use App\Services\OpenBillSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OpenBillSheetSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_config_does_not_queue_a_sheet_sync(): void
    {
        Queue::fake();
        config()->set('google_sheets.enabled', false);
        config()->set('google_sheets.spreadsheet_id', 'test-sheet');
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated();

        Queue::assertNotPushed(SyncOpenBillsToGoogleSheet::class);
    }

    public function test_add_order_exclude_and_discount_queue_a_sheet_with_grouped_active_lines(): void
    {
        Queue::fake();
        $this->enableSheets();
        [$owner, $hotel, $sessionId, $product] = $this->openTableContext();

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'sheet-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        Queue::assertPushed(SyncOpenBillsToGoogleSheet::class, fn (SyncOpenBillsToGoogleSheet $job) => $job->hotelId === $hotel->id);

        $tabs = app(OpenBillSnapshotService::class)->snapshot($hotel->id);
        $this->assertSame('Table 1', $tabs['Open tables'][3][0]);
        $this->assertSame('Dine-in', $tabs['Open tables'][3][1]);
        $this->assertSame('200.00', $tabs['Open tables'][3][7]);
        $this->assertSame(['Table 1', 'Tea', '2', '100.00', '200.00'], $tabs['Lines'][3]);

        $itemId = OrderItem::query()->where('status', '!=', 'cancelled')->value('id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$itemId}/cancel", ['reason' => 'Excluded from bill', 'quantity' => 1])->assertOk();

        $afterCancel = app(OpenBillSnapshotService::class)->snapshot($hotel->id);
        $this->assertSame('100.00', $afterCancel['Open tables'][3][7]);
        $this->assertSame(['Table 1', 'Tea', '1', '100.00', '100.00'], $afterCancel['Lines'][3]);
        $this->assertCount(4, $afterCancel['Lines']);

        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/discount", ['discount_amount' => 10])->assertOk();

        $afterDiscount = app(OpenBillSnapshotService::class)->snapshot($hotel->id);
        $this->assertSame('10.00', $afterDiscount['Open tables'][3][5]);
        $this->assertSame('90.00', $afterDiscount['Open tables'][3][7]);
        Queue::assertPushed(SyncOpenBillsToGoogleSheet::class);
    }

    public function test_paid_and_closed_sessions_leave_the_open_tables_list(): void
    {
        Queue::fake();
        $this->enableSheets();
        [$owner, $hotel, $sessionId, $product] = $this->openTableContext();

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'sheet-bill-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'sheet-pay')->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'method' => 'cash', 'amount' => 100,
        ])->assertOk()->assertJsonPath('data.payment_status', 'paid');

        $tabs = app(OpenBillSnapshotService::class)->snapshot($hotel->id);
        $this->assertCount(3, $tabs['Open tables']);
        $this->assertCount(3, $tabs['Lines']);

        $empty = DiningTable::query()->create(['outlet_id' => $hotel->outlets()->sole()->id, 'name' => 'Table 2', 'code' => 'T2', 'capacity' => 4]);
        $emptySession = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$empty->id}/sessions")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$emptySession}/request-bill")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$emptySession}/close-without-sale", ['reason' => 'Guests left before ordering'])->assertOk();

        $afterClose = app(OpenBillSnapshotService::class)->snapshot($hotel->id);
        $this->assertCount(3, $afterClose['Open tables']);
    }

    public function test_billing_still_succeeds_when_the_sheet_writer_throws(): void
    {
        Queue::fake();
        $this->enableSheets();
        $this->app->instance(GoogleSheetsWriter::class, new class implements GoogleSheetsWriter
        {
            public function rewriteWorkbook(string $spreadsheetId, array $tabs): void
            {
                throw new RuntimeException('google down');
            }
        });
        [$owner, $hotel, $sessionId, $product] = $this->openTableContext();

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'sheet-throw')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $job = new SyncOpenBillsToGoogleSheet($hotel->id);
        $job->handle(app(OpenBillSnapshotService::class));

        $this->assertDatabaseHas('dining_sessions', ['id' => $sessionId, 'status' => 'occupied']);
    }

    private function enableSheets(): void
    {
        config()->set('google_sheets.enabled', true);
        config()->set('google_sheets.spreadsheet_id', 'test-sheet');
    }

    /** @return array{User, Hotel, int, Product} */
    private function openTableContext(): array
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 100, 'track_inventory' => false]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated()->json('data.id');

        return [$owner, $hotel, $sessionId, $product];
    }

    /** @return array{User, Hotel} */
    private function ownerContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha-sheets@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        return [User::query()->where('email', 'asha-sheets@example.test')->sole(), Hotel::query()->sole()];
    }
}
