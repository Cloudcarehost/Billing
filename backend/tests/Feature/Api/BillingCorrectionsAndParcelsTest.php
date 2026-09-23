<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCorrectionsAndParcelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_concurrent_parcels_and_reuse_idle_ones(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();

        $first = $this->actingAs($owner, 'web')->postJson('/api/v1/parcels', ['outlet_id' => $outlet->id])
            ->assertCreated()
            ->assertJsonPath('data.service_type', 'parcel')
            ->assertJsonPath('data.name', 'Parcel 1');
        $firstId = $first->json('data.id');
        $firstSessionId = $first->json('data.active_session.id');

        $this->actingAs($owner, 'web')->postJson('/api/v1/parcels', ['outlet_id' => $outlet->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Parcel 2')
            ->assertJsonPath('data.service_type', 'parcel');

        $this->actingAs($owner, 'web')->getJson('/api/v1/table-public-links')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Parcel 1']);

        $product = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 50, 'track_inventory' => false]);
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'parcel-order')->postJson("/api/v1/dining-sessions/{$firstSessionId}/orders", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$firstSessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$firstSessionId}/invoice")->assertCreated()->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'parcel-pay')->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'method' => 'cash',
            'amount' => 50,
        ])->assertOk();

        $this->actingAs($owner, 'web')->postJson('/api/v1/parcels', ['outlet_id' => $outlet->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.name', 'Parcel 1');
    }

    public function test_unpaid_bill_can_be_reopened_charged_to_an_owner_and_discounted(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 1', 'code' => 'T1', 'capacity' => 4]);
        $tea = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Tea', 'selling_price' => 100, 'track_inventory' => false]);
        $snack = Product::query()->create(['hotel_id' => $hotel->id, 'name' => 'Snack', 'selling_price' => 50, 'track_inventory' => false]);

        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'correction-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", [
            'items' => [
                ['product_id' => $tea->id, 'quantity' => 1],
                ['product_id' => $snack->id, 'quantity' => 1],
            ],
        ])->assertCreated()->assertJsonPath('data.total_amount', '150.00');
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoiceId = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice")
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$invoiceId}/reopen", ['reason' => 'Missed discount and extra snack'])
            ->assertOk()
            ->assertJsonPath('data.status', 'occupied')
            ->assertJsonPath('data.invoice', null);

        $snackItemId = collect($this->actingAs($owner, 'web')->getJson("/api/v1/dining-sessions/{$sessionId}")->json('data.orders.0.items'))
            ->firstWhere('item_name', 'Snack')['id'];
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$snackItemId}/cancel", ['reason' => 'Not ordered'])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/discount", ['discount_amount' => 10])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', '10.00')
            ->assertJsonPath('data.total_amount', '90.00');
        $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();

        $owners = $this->actingAs($owner, 'web')->getJson('/api/v1/billing-owners')->assertOk()->json('data');
        $this->assertSame([['id' => $owner->id, 'name' => 'Asha Owner']], $owners);

        $corrected = $this->actingAs($owner, 'web')->postJson("/api/v1/dining-sessions/{$sessionId}/invoice", [
            'charged_to_user_id' => $owner->id,
        ])->assertCreated();
        $corrected->assertJsonPath('data.charged_to_user_id', $owner->id)
            ->assertJsonPath('data.charged_to.name', 'Asha Owner')
            ->assertJsonPath('data.customer_name', 'Asha Owner')
            ->assertJsonPath('data.discount_amount', '10.00')
            ->assertJsonPath('data.total_amount', '90.00');
        $this->assertNotSame($invoiceId, $corrected->json('data.id'));

        $this->actingAs($owner, 'web')->getJson('/api/v1/dashboard?period=today')
            ->assertOk()
            ->assertJsonPath('data.discounts.bills', 1)
            ->assertJsonPath('data.sales.bills', 1)
            ->assertJsonPath('data.voided_bills', 1);
        $this->assertEquals(10, (float) $this->actingAs($owner, 'web')->getJson('/api/v1/dashboard?period=today')->json('data.discounts.amount'));

        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'corrected-pay')->postJson("/api/v1/invoices/{$corrected->json('data.id')}/payments", [
            'method' => 'cash',
            'amount' => 90,
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/invoices/{$corrected->json('data.id')}/reopen", ['reason' => 'Too late'])
            ->assertUnprocessable();
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
