<?php

namespace Tests\Feature\Api;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_uses_issued_bills_and_recorded_money_expenses(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $main = $hotel->outlets()->sole();
        $bar = $hotel->outlets()->create(['name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR']);

        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $main->id,
            'invoice_number' => 'MAIN-RPT-000001',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 1100,
            'discount_amount' => 100,
            'total_amount' => 1000,
            'balance_amount' => 1000,
        ]);
        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $main->id,
            'invoice_number' => 'MAIN-RPT-000002',
            'status' => 'voided',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 500,
            'discount_amount' => 50,
            'total_amount' => 500,
            'balance_amount' => 500,
        ]);
        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $bar->id,
            'invoice_number' => 'BAR-RPT-000001',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 200,
            'discount_amount' => 0,
            'total_amount' => 200,
            'balance_amount' => 200,
        ]);

        $this->asUser($owner)->postJson('/api/v1/finance/standing-costs', [
            'name' => 'Plot rent',
            'amount' => 5000,
            'pay_cycle' => 'monthly',
            'due_on' => '2026-09-05',
        ])->assertCreated();
        $this->asUser($owner)->postJson('/api/v1/finance/entries', [
            'type' => 'expense',
            'category' => 'Groceries',
            'amount' => 250,
            'comment' => 'Market run',
            'occurred_on' => '2026-09-02',
        ])->assertCreated();
        $this->asUser($owner)->postJson('/api/v1/finance/entries', [
            'type' => 'income',
            'category' => 'Other',
            'amount' => 50,
            'comment' => 'Scrap sale',
            'occurred_on' => '2026-09-03',
        ])->assertCreated();

        $overview = $this->asUser($owner)->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-09-07')->assertOk();
        $overview->assertJsonPath('data.kpis.gross_sales.amount', '1300.00')
            ->assertJsonPath('data.kpis.net_sales.amount', '1200.00')
            ->assertJsonPath('data.kpis.discounts.amount', '100.00')
            ->assertJsonPath('data.kpis.discounts.bills', 1)
            ->assertJsonPath('data.kpis.expenses.amount', '5250.00')
            ->assertJsonPath('data.kpis.surplus.amount', '-4050.00')
            ->assertJsonPath('data.kpis.net_sales.change_percent', null)
            ->assertJsonPath('data.expenses_hotel_wide', false)
            ->assertJsonPath('data.previous_period.from', '2026-08-25')
            ->assertJsonPath('data.previous_period.to', '2026-08-31');

        $this->assertEquals(1300.0, (float) $overview->json('data.kpis.gross_sales.amount'));
        $this->assertEquals((float) $overview->json('data.kpis.net_sales.amount') + (float) $overview->json('data.kpis.discounts.amount'), (float) $overview->json('data.kpis.gross_sales.amount'));
        $this->assertEqualsCanonicalizing(['Plot rent', 'Groceries'], collect($overview->json('data.expense_breakdown'))->pluck('category')->all());
        $this->assertNotContains('Other', collect($overview->json('data.expense_breakdown'))->pluck('category')->all());

        $seriesNet = collect($overview->json('data.series'))->sum(fn ($row) => (float) $row['net_sales']);
        $seriesExpenses = collect($overview->json('data.series'))->sum(fn ($row) => (float) $row['expenses']);
        $this->assertEquals(1200.0, $seriesNet);
        $this->assertEquals(5250.0, $seriesExpenses);

        $outletView = $this->asUser($owner)->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-09-07&outlet_id='.$main->id)->assertOk();
        $outletView->assertJsonPath('data.kpis.net_sales.amount', '1000.00')
            ->assertJsonPath('data.kpis.gross_sales.amount', '1100.00')
            ->assertJsonPath('data.kpis.expenses.amount', '5250.00')
            ->assertJsonPath('data.expenses_hotel_wide', true);
    }

    public function test_month_period_uses_previous_calendar_month_and_export_contains_kpis(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'MAIN-RPT-000010',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-08-10',
            'subtotal' => 400,
            'discount_amount' => 0,
            'total_amount' => 400,
            'balance_amount' => 400,
        ]);
        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'MAIN-RPT-000011',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-10',
            'subtotal' => 800,
            'discount_amount' => 0,
            'total_amount' => 800,
            'balance_amount' => 800,
        ]);

        $overview = $this->asUser($owner)->getJson('/api/v1/reports/overview?period=month&from=2026-09-01&to=2026-09-30')->assertOk();
        $overview->assertJsonPath('data.previous_period.from', '2026-08-01')
            ->assertJsonPath('data.previous_period.to', '2026-08-31')
            ->assertJsonPath('data.kpis.net_sales.amount', '800.00')
            ->assertJsonPath('data.kpis.net_sales.previous', '400.00')
            ->assertJsonPath('data.kpis.net_sales.change_percent', 100);

        $export = $this->asUser($owner)->postJson('/api/v1/reports/exports', [
            'type' => 'business_overview',
            'format' => 'csv',
            'filters' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month'],
        ])->assertStatus(202);
        $exportId = $export->json('data.id');
        $this->assertSame('completed', $this->asUser($owner)->getJson('/api/v1/reports/exports')->json('data.0.status'));
        $csv = $this->asUser($owner)->get("/api/v1/reports/exports/{$exportId}/download")->assertOk()->streamedContent();
        $this->assertStringContainsString('Net sales', $csv);
        $this->assertStringContainsString('800.00', $csv);
    }

    public function test_menu_performance_uses_issued_bill_lines_and_excludes_voided_bills(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $main = $hotel->outlets()->sole();
        $bar = $hotel->outlets()->create(['name' => 'Bar', 'code' => 'BAR', 'invoice_prefix' => 'BAR']);
        $issued = Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $main->id,
            'invoice_number' => 'MAIN-MENU-000001',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 700,
            'total_amount' => 700,
            'balance_amount' => 700,
        ]);
        $this->addLine($issued, 'Paneer makhani', 'Mains', 2, '500.00');
        $this->addLine($issued, 'Sprite', 'Drinks', 2, '200.00');
        $voided = Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $main->id,
            'invoice_number' => 'MAIN-MENU-000002',
            'status' => 'voided',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 300,
            'total_amount' => 300,
            'balance_amount' => 300,
        ]);
        $this->addLine($voided, 'Chicken lollipop', 'Starters', 3, '300.00');
        $barBill = Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $bar->id,
            'invoice_number' => 'BAR-MENU-000001',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 150,
            'total_amount' => 150,
            'balance_amount' => 150,
        ]);
        $this->addLine($barBill, 'Beer', 'Drinks', 1, '150.00');

        $overview = $this->asUser($owner)->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-09-01')->assertOk();
        $categories = collect($overview->json('data.menu_performance.categories'));
        $this->assertSame(['Mains', 'Drinks'], $categories->pluck('category')->all());
        $this->assertEquals(500.0, (float) $categories->firstWhere('category', 'Mains')['net_sales']);
        $this->assertEquals(350.0, (float) $categories->firstWhere('category', 'Drinks')['net_sales']);
        $this->assertEquals(2, $categories->firstWhere('category', 'Mains')['quantity']);
        $this->assertNotContains('Starters', $categories->pluck('category')->all());
        $items = collect($overview->json('data.menu_performance.items'));
        $this->assertSame('Paneer makhani', $items->first()['item']);
        $this->assertNotContains('Chicken lollipop', $items->pluck('item')->all());

        $outletView = $this->asUser($owner)->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-09-01&outlet_id='.$main->id)->assertOk();
        $this->assertEquals(200.0, (float) collect($outletView->json('data.menu_performance.categories'))->firstWhere('category', 'Drinks')['net_sales']);
        $this->assertNotContains('Beer', collect($outletView->json('data.menu_performance.items'))->pluck('item')->all());
    }

    public function test_payment_analysis_uses_transactions_and_keeps_outstanding_separate(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $paid = Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'MAIN-PAY-000001',
            'status' => 'issued',
            'payment_status' => 'paid',
            'business_date' => '2026-09-01',
            'subtotal' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 800,
            'balance_amount' => 0,
        ]);
        Payment::query()->create([
            'invoice_id' => $paid->id,
            'type' => 'payment',
            'method' => 'cash',
            'amount' => 600,
            'paid_at' => '2026-09-01 12:00:00',
        ]);
        Payment::query()->create([
            'invoice_id' => $paid->id,
            'type' => 'payment',
            'method' => 'upi',
            'amount' => 400,
            'paid_at' => '2026-09-01 12:05:00',
        ]);
        Payment::query()->create([
            'invoice_id' => $paid->id,
            'type' => 'refund',
            'method' => 'cash',
            'amount' => -200,
            'paid_at' => '2026-09-01 18:00:00',
        ]);
        $open = Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'MAIN-PAY-000002',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 250,
            'total_amount' => 250,
            'balance_amount' => 250,
        ]);
        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'MAIN-PAY-000003',
            'status' => 'voided',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 90,
            'total_amount' => 90,
            'balance_amount' => 90,
        ]);

        $overview = $this->asUser($owner)->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-09-01')->assertOk();
        $overview->assertJsonPath('data.payments.collected', '800.00')
            ->assertJsonPath('data.payments.outstanding', '250.00');
        $methods = collect($overview->json('data.payments.methods'))->keyBy('method');
        $this->assertEquals(400.0, (float) $methods['cash']['amount']);
        $this->assertSame(1, $methods['cash']['count']);
        $this->assertEquals(400.0, (float) $methods['upi']['amount']);
        $this->assertSame(1, $methods['upi']['count']);
        $this->assertArrayNotHasKey('card', $methods->all());
    }

    public function test_overview_is_forbidden_without_reports_view(): void
    {
        [, $hotel] = $this->ownerContext();
        $waiter = $this->member($hotel, 'waiter', 'waiter-reports@example.test');

        $this->asUser($waiter)->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-09-07')->assertForbidden();
    }

    private function addLine(Invoice $invoice, string $name, string $category, float $quantity, string $total): void
    {
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_name' => $name,
            'category_name' => $category,
            'quantity' => $quantity,
            'unit_price' => $total,
            'line_subtotal' => $total,
            'line_total' => $total,
        ]);
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
