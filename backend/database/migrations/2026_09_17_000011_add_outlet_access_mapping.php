<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(['code' => 'outlets.all'], [
            'name' => 'Access every outlet', 'group' => 'outlets', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('code', 'outlets.all')->value('id');
        DB::table('roles')->where('slug', 'manager')->pluck('id')->each(fn (int $roleId) => DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId, 'permission_id' => $permissionId,
        ]));

        Schema::create('hotel_user_outlets', function (Blueprint $table) {
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['hotel_id', 'user_id', 'outlet_id']);
            $table->index(['outlet_id', 'user_id']);
        });

        DB::table('hotel_user')->where('is_active', true)->orderBy('hotel_id')->each(function (object $membership): void {
            $now = now();
            $rows = DB::table('outlets')->where('hotel_id', $membership->hotel_id)->pluck('id')->map(fn (int $outletId) => [
                'hotel_id' => $membership->hotel_id, 'user_id' => $membership->user_id, 'outlet_id' => $outletId,
                'created_at' => $now, 'updated_at' => $now,
            ])->all();
            if ($rows) {
                DB::table('hotel_user_outlets')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_user_outlets');
        $permissionId = DB::table('permissions')->where('code', 'outlets.all')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
