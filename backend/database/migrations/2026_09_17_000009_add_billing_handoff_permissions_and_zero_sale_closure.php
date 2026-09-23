<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_sessions', function (Blueprint $table) {
            $table->foreignId('closed_without_sale_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->text('closed_without_sale_reason')->nullable()->after('closed_without_sale_by');
        });

        $now = now();
        foreach ([
            ['name' => 'Request a table bill', 'code' => 'billing.request', 'group' => 'billing'],
            ['name' => 'Close zero-value sessions without sale', 'code' => 'billing.close_without_sale', 'group' => 'billing'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(['code' => $permission['code']], $permission + ['created_at' => $now, 'updated_at' => $now]);
        }

        $requestId = DB::table('permissions')->where('code', 'billing.request')->value('id');
        $closeId = DB::table('permissions')->where('code', 'billing.close_without_sale')->value('id');
        $requestRoles = DB::table('roles')->where('is_owner', true)->orWhereIn('slug', ['manager', 'cashier', 'waiter'])->pluck('id');
        $closeRoles = DB::table('roles')->where('is_owner', true)->orWhereIn('slug', ['manager', 'cashier'])->pluck('id');

        DB::table('permission_role')->insertOrIgnore($requestRoles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $requestId])->all());
        DB::table('permission_role')->insertOrIgnore($closeRoles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $closeId])->all());
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('code', ['billing.request', 'billing.close_without_sale'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('dining_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_without_sale_by');
            $table->dropColumn('closed_without_sale_reason');
        });
    }
};
