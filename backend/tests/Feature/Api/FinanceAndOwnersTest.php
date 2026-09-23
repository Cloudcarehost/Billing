<?php

namespace Tests\Feature\Api;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\StandingCost;
use App\Models\User;
use App\Services\HotelMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceAndOwnersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_owner_can_be_assigned_and_the_last_owner_cannot_be_removed(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $ownerRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'owner')->sole();
        $waiterRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();

        $this->asUser($owner)->putJson("/api/v1/users/{$owner->id}", [
            'role_id' => $waiterRole->id,
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonPath('message', 'A hotel must retain at least one active Owner.');

        $second = $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Ravi Partner',
            'email' => 'ravi@example.test',
            'password' => 'StrongPassword1',
            'role_id' => $waiterRole->id,
            'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
            'salary_amount' => 100,
            'pay_cycle' => 'daily',
        ])->assertCreated();
        $secondId = $second->json('data.id');
        $this->assertEquals(100, (float) $second->json('data.membership.salary_amount'));
        $this->assertSame('daily', $second->json('data.membership.pay_cycle'));

        $this->asUser($owner)->putJson("/api/v1/users/{$secondId}", [
            'name' => 'Ravi Partner',
            'role_id' => $ownerRole->id,
            'is_active' => true,
            'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
        ])->assertOk()->assertJsonPath('data.membership.role.slug', 'owner');

        $owners = $this->asUser($owner)->getJson('/api/v1/billing-owners')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [['id' => $owner->id, 'name' => 'Asha Owner'], ['id' => $secondId, 'name' => 'Ravi Partner']],
            $owners,
        );

        $this->asUser($owner)->putJson("/api/v1/users/{$owner->id}", [
            'role_id' => $waiterRole->id,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.membership.role.slug', 'waiter');

        $ravi = User::query()->findOrFail($secondId);
        $this->asUser($ravi)->putJson("/api/v1/users/{$ravi->id}", [
            'role_id' => $waiterRole->id,
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonPath('message', 'A hotel must retain at least one active Owner.');
    }

    public function test_finance_summary_subtracts_standing_costs_and_entries_from_billed_totals(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $outlet = $hotel->outlets()->sole();
        $waiterRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();
        Invoice::query()->create([
            'hotel_id' => $hotel->id,
            'outlet_id' => $outlet->id,
            'invoice_number' => 'MAIN-MONEY-000001',
            'status' => 'issued',
            'payment_status' => 'unpaid',
            'business_date' => '2026-09-01',
            'subtotal' => 1000,
            'total_amount' => 1000,
            'balance_amount' => 1000,
        ]);

        $cook = $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Cook Daily',
            'email' => 'cook@example.test',
            'password' => 'StrongPassword1',
            'role_id' => $waiterRole->id,
            'outlet_ids' => [$outlet->id],
            'salary_amount' => 100,
            'pay_cycle' => 'daily',
        ])->assertCreated()->json('data');
        DB::table('hotel_user')->where('user_id', $cook['id'])->update(['joined_at' => '2026-08-01 00:00:00']);
        $late = $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Late Hire',
            'email' => 'late@example.test',
            'password' => 'StrongPassword1',
            'role_id' => $waiterRole->id,
            'outlet_ids' => [$outlet->id],
            'salary_amount' => 500,
            'pay_cycle' => 'daily',
        ])->assertCreated()->json('data');
        DB::table('hotel_user')->where('user_id', $late['id'])->update(['joined_at' => '2026-10-01 00:00:00']);
        $inactiveStaff = $this->asUser($owner)->postJson('/api/v1/users', [
            'name' => 'Inactive Cook',
            'email' => 'inactive-cook@example.test',
            'password' => 'StrongPassword1',
            'role_id' => $waiterRole->id,
            'is_active' => false,
            'outlet_ids' => [$outlet->id],
            'salary_amount' => 999,
            'pay_cycle' => 'monthly',
        ])->assertCreated()->json('data');

        $rent = $this->asUser($owner)->postJson('/api/v1/finance/standing-costs', [
            'name' => 'Plot rent',
            'amount' => 5000,
            'pay_cycle' => 'monthly',
            'due_on' => '2026-09-05',
        ])->assertCreated()->json('data');
        $this->assertSame('2026-09-05', $rent['due_on']);
        $light = $this->asUser($owner)->postJson('/api/v1/finance/standing-costs', [
            'name' => 'Light bill',
            'amount' => 800,
            'pay_cycle' => 'monthly',
        ])->assertCreated()->json('data');
        $this->asUser($owner)->putJson("/api/v1/finance/standing-costs/{$light['id']}", ['is_active' => false])->assertOk();

        $expense = $this->asUser($owner)->postJson('/api/v1/finance/entries', [
            'type' => 'expense',
            'category' => 'Groceries',
            'amount' => 200,
            'comment' => 'Market run',
            'occurred_on' => '2026-09-02',
        ])->assertCreated()->json('data');
        $this->asUser($owner)->postJson('/api/v1/finance/entries', [
            'type' => 'income',
            'category' => 'Other',
            'amount' => 50,
            'comment' => 'Scrap sale',
            'occurred_on' => '2026-09-03',
        ])->assertCreated();

        $this->asUser($owner)->putJson("/api/v1/finance/entries/{$expense['id']}", [
            'amount' => 250,
            'comment' => 'Market run plus oil',
        ])->assertOk()->assertJsonPath('data.amount', '250.00');

        $summary = $this->asUser($owner)->getJson('/api/v1/finance/summary?from=2026-09-01&to=2026-09-07')->assertOk();
        $summary->assertJsonPath('data.billed', '1000.00')
            ->assertJsonPath('data.other_income', '50.00')
            ->assertJsonPath('data.extra_expenses', '250.00')
            ->assertJsonPath('data.standing_total', '5700.00')
            ->assertJsonPath('data.expenses', '5950.00')
            ->assertJsonPath('data.remaining', '-4900.00');

        $standingNames = collect($summary->json('data.standing'))->pluck('name')->all();
        $this->assertContains('Cook Daily salary', $standingNames);
        $this->assertContains('Plot rent', $standingNames);
        $plot = collect($summary->json('data.standing'))->firstWhere('name', 'Plot rent');
        $this->assertSame('2026-09-05', $plot['due_on'] ?? null);
        $this->assertSame(['2026-09-05'], $plot['due_dates'] ?? null);
        $this->assertNotContains('Late Hire salary', $standingNames);
        $this->assertNotContains('Inactive Cook salary', $standingNames);
        $this->assertNotContains('Light bill', $standingNames);

        $entryCategories = collect($summary->json('data.entries'))->pluck('category')->all();
        $this->assertContains('Plot rent', $entryCategories);
        $this->assertContains('Cook Daily salary', $entryCategories);
        $this->assertContains('Groceries', $entryCategories);
        $this->assertContains('Other', $entryCategories);

        $afterDue = $this->asUser($owner)->getJson('/api/v1/finance/summary?from=2026-09-08&to=2026-09-14')->assertOk();
        $this->assertNotContains('Plot rent', collect($afterDue->json('data.standing'))->pluck('name')->all());
        $this->assertNotContains('Plot rent', collect($afterDue->json('data.entries'))->pluck('category')->all());

        $this->asUser($owner)->deleteJson("/api/v1/finance/entries/{$expense['id']}")->assertOk();
        $afterDelete = $this->asUser($owner)->getJson('/api/v1/finance/summary?from=2026-09-01&to=2026-09-07')->assertOk();
        $afterDelete->assertJsonPath('data.extra_expenses', '0.00')
            ->assertJsonPath('data.remaining', '-4650.00');

        $dashboard = $this->asUser($owner)->getJson('/api/v1/dashboard?from=2026-09-01&to=2026-09-07')->assertOk();
        $this->assertEquals((float) $summary->json('data.billed'), (float) $dashboard->json('data.sales.amount'));

        $this->assertSame('Plot rent', StandingCost::query()->find($rent['id'])?->name);
        $this->assertFalse((bool) $inactiveStaff['membership']['is_active']);
    }

    public function test_waiters_cannot_open_money_and_managers_can(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $waiter = $this->member($hotel, 'waiter', 'waiter-money@example.test');
        $manager = $this->member($hotel, 'manager', 'manager-money@example.test');

        $this->asUser($waiter)->getJson('/api/v1/finance/summary')->assertForbidden();
        $this->asUser($waiter)->postJson('/api/v1/finance/entries', [
            'type' => 'expense', 'category' => 'Groceries', 'amount' => 10, 'occurred_on' => '2026-09-01',
        ])->assertForbidden();
        $this->asUser($manager)->getJson('/api/v1/finance/summary?period=month')->assertOk();
        $this->asUser($manager)->postJson('/api/v1/finance/standing-costs', [
            'name' => 'Plot rent', 'amount' => 1000, 'pay_cycle' => 'monthly',
        ])->assertCreated();
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
