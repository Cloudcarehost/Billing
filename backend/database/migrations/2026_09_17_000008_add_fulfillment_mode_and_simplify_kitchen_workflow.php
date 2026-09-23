<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('inventory_deduction_rule', 20)->default('preparing')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('fulfillment_mode', 20)->default('direct')->after('kitchen_station_id');
            $table->index(['hotel_id', 'fulfillment_mode', 'is_active']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('fulfillment_mode', 20)->default('direct')->after('kitchen_station_id');
            $table->timestamp('preparing_at')->nullable()->after('kitchen_note');
            $table->index(['kitchen_station_id', 'status', 'ready_at']);
        });

        DB::table('products')->whereNotNull('kitchen_station_id')->update(['fulfillment_mode' => 'kitchen']);
        DB::table('order_items')->whereNotNull('kitchen_station_id')->update(['fulfillment_mode' => 'kitchen']);
        DB::table('hotels')->where('inventory_deduction_rule', 'accepted')->update(['inventory_deduction_rule' => 'preparing']);

        DB::table('order_items')->where('status', 'accepted')->update([
            'status' => 'preparing',
            'preparing_at' => DB::raw('COALESCE(accepted_at, updated_at)'),
        ]);
        DB::table('orders')->where('status', 'accepted')->update([
            'status' => 'preparing',
            'started_at' => DB::raw('COALESCE(accepted_at, updated_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['kitchen_station_id', 'status', 'ready_at']);
            $table->dropColumn(['fulfillment_mode', 'preparing_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'fulfillment_mode', 'is_active']);
            $table->dropColumn('fulfillment_mode');
        });

        DB::table('hotels')->where('inventory_deduction_rule', 'preparing')->update(['inventory_deduction_rule' => 'accepted']);
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('inventory_deduction_rule', 20)->default('accepted')->change();
        });
    }
};
