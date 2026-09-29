<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelMembershipService;
use App\Support\HotelDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_list_previous_bills_without_reports_access(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $cashier = $this->member($hotel, 'cashier', 'cashier@example.test');
        $invoice = $this->issueBill($owner, $hotel, 'Table 1', 'T1', 'Ravi Guest', '9876500001', true);

        $this->asUser($cashier)->getJson('/api/v1/reports/invoices')->assertForbidden();
        $this->asUser($cashier)->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.id', $invoice['id'])
            ->assertJsonPath('data.0.invoice_number', $invoice['number'])
            ->assertJsonPath('data.0.customer_name', 'Ravi Guest')
            ->assertJsonPath('data.0.table_name', 'Table 1')
            ->assertJsonPath('data.0.payment_status', 'paid')
            ->assertJsonPath('data.0.payment_methods.0', 'cash');
    }

    public function test_previous_bills_can_be_searched_and_filtered(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $cashier = $this->member($hotel, 'cashier', 'cashier@example.test');
        $paid = $this->issueBill($owner, $hotel, 'Table 2', 'T2', 'Asha Guest', '9000000002', true);
        $due = $this->issueBill($owner, $hotel, 'Garden 4', 'G4', 'Kiran Guest', '9000000003', false);

        $this->asUser($cashier)->getJson('/api/v1/invoices?q='.$paid['number'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $paid['id']);
        $this->asUser($cashier)->getJson('/api/v1/invoices?q=Garden')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $due['id']);
        $this->asUser($cashier)->getJson('/api/v1/invoices?q=Kiran')
            ->assertOk()
            ->assertJsonPath('data.0.id', $due['id']);
        $this->asUser($cashier)->getJson('/api/v1/invoices?payment_status=unpaid')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $due['id']);
        $this->asUser($cashier)->getJson('/api/v1/invoices?status=issued')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->asUser($cashier)->getJson('/api/v1/invoices?from=2000-01-01&to=2000-01-01')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_previous_bills_are_paginated(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $cashier = $this->member($hotel, 'cashier', 'cashier@example.test');
        $outlet = $hotel->outlets()->sole();
        $today = HotelDate::businessDate($hotel);
        foreach (range(1, 21) as $number) {
            Invoice::query()->create([
                'hotel_id' => $hotel->id,
                'outlet_id' => $outlet->id,
                'created_by' => $owner->id,
                'invoice_number' => sprintf('MAIN-PAGE-%02d', $number),
                'status' => 'issued',
                'payment_status' => 'paid',
                'business_date' => $today,
                'billed_at' => now()->subMinutes($number),
                'total_amount' => 100,
                'paid_amount' => 100,
                'balance_amount' => 0,
            ]);
        }

        $this->asUser($cashier)->getJson('/api/v1/invoices?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(20, 'data');
        $this->asUser($cashier)->getJson('/api/v1/invoices?per_page=20&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_waiter_cannot_list_previous_bills(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $waiter = $this->member($hotel, 'waiter', 'waiter@example.test');
        $this->issueBill($owner, $hotel, 'Table 8', 'T8', 'Walk-in', null, true);

        $this->asUser($waiter)->getJson('/api/v1/invoices')->assertForbidden();
    }

    /** @return array{id: int, number: string} */
    private function issueBill(User $actor, Hotel $hotel, string $tableName, string $code, string $guestName, ?string $phone, bool $pay): array
    {
        $outlet = $hotel->outlets()->sole();
        $product = Product::query()->firstOrCreate(
            ['hotel_id' => $hotel->id, 'name' => 'Tea'],
            ['selling_price' => 100, 'cost_price' => 40, 'tax_rate' => 0, 'track_inventory' => false],
        );
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => $tableName, 'code' => $code, 'capacity' => 4]);
        $sessionId = $this->asUser($actor)->postJson("/api/v1/tables/{$table->id}/sessions", ['guest_count' => 2])->json('data.id');
        $this->asUser($actor)->withHeader('Idempotency-Key', "history-order-{$table->id}")
            ->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated();
        $this->asUser($actor)->postJson("/api/v1/dining-sessions/{$sessionId}/request-bill")->assertOk();
        $invoice = $this->asUser($actor)->postJson("/api/v1/dining-sessions/{$sessionId}/invoice", [
            'customer_name' => $guestName,
            'customer_phone' => $phone,
        ])->assertCreated()->json('data');
        if ($pay) {
            $this->asUser($actor)->withHeader('Idempotency-Key', "history-pay-{$table->id}")
                ->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['method' => 'cash', 'amount' => $invoice['total_amount']])
                ->assertOk();
        }

        return ['id' => $invoice['id'], 'number' => $invoice['invoice_number']];
    }

    private function member(Hotel $hotel, string $slug, string $email): User
    {
        $role = Role::query()->where('hotel_id', $hotel->id)->where('slug', $slug)->sole();

        return app(HotelMembershipService::class)->create($hotel, [
            'name' => ucfirst($slug).' User',
            'email' => $email,
            'password' => 'StrongPassword1',
            'role_id' => $role->id,
            'is_active' => true,
            'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
        ]);
    }

    private function asUser(User $user): static
    {
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this->withHeader('Origin', 'http://localhost:5173');
    }

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
