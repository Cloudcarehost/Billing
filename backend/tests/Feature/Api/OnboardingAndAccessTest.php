<?php

namespace Tests\Feature\Api;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_onboarding_creates_an_owner_hotel_outlet_and_default_roles(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel',
            'outlet_name' => 'Main Restaurant',
            'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner',
            'owner_email' => 'asha@example.test',
            'password' => 'StrongPassword1',
            'password_confirmation' => 'StrongPassword1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.hotel.name', 'Lakeview Hotel');

        $hotel = Hotel::query()->sole();
        $this->assertDatabaseCount('permissions', 37);
        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseHas('hotel_user', [
            'hotel_id' => $hotel->id,
            'user_id' => User::query()->sole()->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_read_context_and_create_a_waiter_membership(): void
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

        $owner = User::query()->where('email', 'asha@example.test')->sole();
        $hotel = Hotel::query()->sole();
        $waiter = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.hotel.id', $hotel->id)
            ->assertJsonPath('data.role.slug', 'owner');

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/users', [
                'name' => 'Waseem Waiter',
                'email' => 'waseem@example.test',
                'password' => 'StrongPassword1',
                'role_id' => $waiter->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.membership.role.slug', 'waiter');
    }

    public function test_owner_can_change_role_permissions(): void
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

        $hotel = Hotel::query()->sole();
        $owner = User::query()->where('email', 'asha@example.test')->sole();
        $waiterRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'waiter')->sole();

        $this->actingAs($owner, 'web')->putJson("/api/v1/roles/{$waiterRole->id}", [
            'permission_ids' => [],
        ])->assertOk()
            ->assertJsonPath('data.id', $waiterRole->id)
            ->assertJsonCount(0, 'data.permissions');

    }

    public function test_non_owner_cannot_manage_roles(): void
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

        $hotel = Hotel::query()->sole();
        $managerRole = Role::query()->where('hotel_id', $hotel->id)->where('slug', 'manager')->sole();
        $manager = User::factory()->create();
        $hotel->users()->attach($manager->id, ['role_id' => $managerRole->id, 'is_active' => true, 'joined_at' => now()]);

        $this->actingAs($manager, 'web')->postJson('/api/v1/roles', [
            'name' => 'Supervisor',
            'permission_ids' => [],
        ])->assertForbidden();
    }
}
