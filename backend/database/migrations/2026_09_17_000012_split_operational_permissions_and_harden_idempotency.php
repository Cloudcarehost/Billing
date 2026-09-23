<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            ['group' => 'tables', 'code' => 'tables.configure', 'name' => 'Create and configure tables'],
            ['group' => 'tables', 'code' => 'sessions.open', 'name' => 'Open dining sessions'],
            ['group' => 'tables', 'code' => 'sessions.assign', 'name' => 'Assign sessions to another waiter'],
            ['group' => 'orders', 'code' => 'orders.cancel', 'name' => 'Cancel pending order items'],
            ['group' => 'orders', 'code' => 'orders.cancel_prepared', 'name' => 'Cancel preparing or ready items'],
            ['group' => 'orders', 'code' => 'orders.cancel_served', 'name' => 'Cancel served items'],
            ['group' => 'orders', 'code' => 'orders.cancel_high_value', 'name' => 'Approve high-value cancellations'],
        ];
        DB::table('permissions')->upsert(array_map(fn (array $permission) => $permission + ['created_at' => $now, 'updated_at' => $now], $permissions), ['code'], ['group', 'name', 'updated_at']);

        $ids = DB::table('permissions')->whereIn('code', array_column($permissions, 'code'))->pluck('id', 'code');
        $roles = DB::table('roles')->get(['id', 'slug', 'is_owner']);
        $rolePermissions = [
            'manager' => ['tables.configure', 'sessions.open', 'sessions.assign', 'orders.cancel', 'orders.cancel_prepared', 'orders.cancel_served', 'orders.cancel_high_value'],
            'cashier' => ['sessions.open', 'sessions.assign', 'orders.cancel'],
            'waiter' => ['sessions.open', 'orders.cancel'],
            'kitchen' => ['orders.cancel', 'orders.cancel_prepared'],
        ];
        foreach ($roles as $role) {
            $codes = $role->is_owner ? array_keys($ids->all()) : ($rolePermissions[$role->slug] ?? []);
            foreach ($codes as $code) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $ids[$code], 'role_id' => $role->id]);
            }
        }

        $legacyTablePermission = DB::table('permissions')->where('code', 'tables.manage')->value('id');
        if ($legacyTablePermission) {
            DB::table('permission_role')->where('permission_id', $legacyTablePermission)->delete();
            DB::table('permissions')->where('id', $legacyTablePermission)->delete();
        }

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('status', 20)->default('processing')->after('scope');
            $table->string('request_hash', 64)->nullable()->after('key');
            $table->unsignedSmallInteger('response_status')->nullable()->after('response');
            $table->timestamp('locked_at')->nullable()->after('response_status');
            $table->index(['status', 'expires_at']);
        });
        DB::table('idempotency_keys')->whereNotNull('response')->update(['status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['status', 'request_hash', 'response_status', 'locked_at']);
        });

        $codes = ['tables.configure', 'sessions.open', 'sessions.assign', 'orders.cancel', 'orders.cancel_prepared', 'orders.cancel_served', 'orders.cancel_high_value'];
        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        DB::table('permissions')->insertOrIgnore(['group' => 'tables', 'code' => 'tables.manage', 'name' => 'Assign and manage tables', 'created_at' => now(), 'updated_at' => now()]);
    }
};
