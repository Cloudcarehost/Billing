<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDeviceSession;
use App\Services\AuthAccessCache;
use App\Services\HotelMembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessDayAndAuthCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_am_invoice_uses_previous_business_day_and_period(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $hotel->update(['timezone' => 'Asia/Kolkata', 'business_day_starts_at' => '05:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-01-01 01:00:00', 'Asia/Kolkata'));

        $invoice = $this->issueInvoice($owner, $hotel);

        $this->assertSame('2025-12-31', $invoice->business_date->toDateString());
        $this->assertStringContainsString('-202512-', $invoice->invoice_number);

        $this->actingAs($owner, 'web')->getJson('/api/v1/reports/invoices?period=today')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id);

        $this->actingAs($owner, 'web')->getJson('/api/v1/reports/invoices?from=2026-01-01&to=2026-01-01')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_end_day_records_the_business_date_and_warns_about_open_tables(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $hotel->update(['timezone' => 'Asia/Kolkata', 'business_day_starts_at' => '05:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-01-01 01:00:00', 'Asia/Kolkata'));
        $outlet = $hotel->outlets()->sole();
        DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 4', 'code' => 'T4', 'capacity' => 4]);
        $this->actingAs($owner, 'web')->postJson('/api/v1/tables/'.DiningTable::query()->sole()->id.'/sessions', ['guest_count' => 2])
            ->assertCreated();

        $this->actingAs($owner, 'web')->postJson('/api/v1/hotel/end-day')
            ->assertOk()
            ->assertJsonPath('data.business_date', '2025-12-31')
            ->assertJsonPath('data.open_sessions', 1)
            ->assertJsonPath('data.already_ended', false);

        $this->assertSame('2025-12-31', $hotel->fresh()->last_ended_business_date->toDateString());

        $this->actingAs($owner, 'web')->postJson('/api/v1/hotel/end-day')
            ->assertOk()
            ->assertJsonPath('data.already_ended', true);
    }

    public function test_revoked_device_session_is_rejected_immediately(): void
    {
        $this->ownerContext();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/login', [
            'email' => 'asha@example.test',
            'password' => 'StrongPassword1',
        ])->assertOk();

        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')->assertOk();
        $device = UserDeviceSession::query()->sole();
        $this->withHeader('Origin', 'http://localhost:5173')->deleteJson("/api/v1/security/sessions/{$device->id}")->assertOk();
        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_last_seen_is_not_written_on_every_request(): void
    {
        $this->ownerContext();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/login', [
            'email' => 'asha@example.test',
            'password' => 'StrongPassword1',
        ])->assertOk();

        $first = UserDeviceSession::query()->sole()->last_seen_at;
        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')->assertOk();
        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/me')->assertOk();
        $this->assertEquals($first?->timestamp, UserDeviceSession::query()->sole()->last_seen_at?->timestamp);
    }

    public function test_role_permission_changes_apply_on_the_next_request(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $waiterRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();
        $waiter = app(HotelMembershipService::class)->create($hotel, [
            'name' => 'Waseem Waiter',
            'email' => 'waseem@example.test',
            'password' => 'StrongPassword1',
            'role_id' => $waiterRole->id,
            'is_active' => true,
        ]);

        $cached = app(AuthAccessCache::class)->remember($waiter, $hotel->id);
        $this->assertNotEmpty($cached['permissions']);

        $this->actingAs($owner, 'web')->putJson("/api/v1/roles/{$waiterRole->id}", [
            'permission_ids' => [],
        ])->assertOk();

        $fresh = app(AuthAccessCache::class)->remember($waiter, $hotel->id);
        $this->assertSame([], $fresh['permissions']);
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

    private function issueInvoice(User $owner, Hotel $hotel): Invoice
    {
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Sprite', 'selling_price' => 40, 'track_inventory' => false, 'fulfillment_mode' => 'direct']);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'biz-day-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")->assertCreated()->json('data.id');

        return Invoice::query()->findOrFail($invoiceId);
    }
}
