<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\StaffRecord;
use App\Models\User;
use App\Services\HotelMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_staff_records_without_changing_money(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $staff = $this->member($hotel, 'waiter', 'leave-waiter@example.test');
        $before = $this->asUser($owner)->getJson('/api/v1/finance/summary?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

        $leave = $this->asUser($owner)->postJson('/api/v1/staff-records', [
            'user_id' => $staff->id,
            'record_type' => 'leave',
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-22',
            'leave_duration' => 'full_day',
            'note' => 'Family function',
        ])->assertCreated()
            ->assertJsonPath('data.staff_name_snapshot', 'Waiter User')
            ->assertJsonPath('data.note', 'Family function');

        $advance = $this->asUser($owner)->postJson('/api/v1/staff-records', [
            'user_id' => $staff->id,
            'record_type' => 'advance_paid',
            'start_date' => '2026-10-23',
            'amount' => 2000,
            'note' => 'Cash note only',
        ])->assertCreated()->assertJsonPath('data.amount', '2000.00');

        $this->asUser($owner)->patchJson('/api/v1/staff-records/'.$leave->json('data.id'), [
            'user_id' => $staff->id,
            'record_type' => 'leave',
            'start_date' => '2026-10-21',
            'end_date' => '2026-10-21',
            'leave_duration' => 'first_half',
            'note' => 'Corrected to half day',
        ])->assertOk()->assertJsonPath('data.leave_duration', 'first_half');

        $this->asUser($owner)->getJson('/api/v1/staff-records?user_id='.$staff->id.'&record_type=leave&from=2026-10-01&to=2026-10-31')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.note', 'Corrected to half day');

        $after = $this->asUser($owner)->getJson('/api/v1/finance/summary?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertSame($before['standing_total'], $after['standing_total']);
        $this->assertSame($before['extra_expenses'], $after['extra_expenses']);
        $this->assertSame($before['remaining'], $after['remaining']);

        $this->asUser($owner)->deleteJson('/api/v1/staff-records/'.$advance->json('data.id'))->assertOk();
        $this->assertSoftDeleted('staff_records', ['id' => $advance->json('data.id')]);
        $this->assertTrue(ActivityLog::query()->whereIn('action', ['staff_record.created', 'staff_record.updated', 'staff_record.deleted'])->count() >= 4);
    }

    public function test_validation_permissions_inactive_history_and_hotel_isolation(): void
    {
        [$owner, $hotel] = $this->ownerContext();
        $staff = $this->member($hotel, 'waiter', 'record-waiter@example.test');

        $this->asUser($owner)->postJson('/api/v1/staff-records', [
            'user_id' => $staff->id, 'record_type' => 'leave', 'start_date' => '2026-10-20',
            'end_date' => '2026-10-22', 'leave_duration' => 'first_half',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_duration');

        $record = $this->asUser($owner)->postJson('/api/v1/staff-records', [
            'user_id' => $staff->id, 'record_type' => 'note', 'start_date' => '2026-10-20', 'note' => 'Remember this',
        ])->assertCreated();

        $role = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();
        $this->asUser($owner)->putJson('/api/v1/users/'.$staff->id, [
            'role_id' => $role->id, 'is_active' => false, 'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
        ])->assertOk();
        $this->asUser($owner)->getJson('/api/v1/staff-records')->assertOk()
            ->assertJsonPath('data.0.id', $record->json('data.id'))
            ->assertJsonPath('data.0.staff_name_snapshot', 'Waiter User');

        $this->asUser($staff)->getJson('/api/v1/staff-records')->assertForbidden();
        $this->asUser($staff)->postJson('/api/v1/staff-records', [
            'user_id' => $staff->id, 'record_type' => 'note', 'start_date' => '2026-10-21', 'note' => 'Denied',
        ])->assertForbidden();

        $otherHotel = Hotel::query()->create(['name' => 'Other Hotel', 'slug' => 'other-hotel']);
        StaffRecord::query()->create([
            'hotel_id' => $otherHotel->id, 'user_id' => $owner->id, 'record_type' => 'note',
            'start_date' => '2026-10-20', 'staff_name_snapshot' => 'Hidden Staff', 'note' => 'Private other hotel note',
        ]);
        $response = $this->asUser($owner)->getJson('/api/v1/staff-records')->assertOk();
        $this->assertNotContains('Hidden Staff', collect($response->json('data'))->pluck('staff_name_snapshot')->all());
    }

    private function member(Hotel $hotel, string $slug, string $email): User
    {
        $role = Role::query()->where('hotel_id', $hotel->id)->where('slug', $slug)->sole();

        return app(HotelMembershipService::class)->create($hotel, [
            'name' => ucfirst($slug).' User', 'email' => $email, 'password' => 'StrongPassword1',
            'role_id' => $role->id, 'is_active' => true, 'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
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
