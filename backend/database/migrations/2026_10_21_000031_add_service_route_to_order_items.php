<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('service_route', 20)->default('direct')->after('fulfillment_mode');
        });

        DB::table('order_items')
            ->whereNotNull('product_id')
            ->update([
                'service_route' => DB::raw('(SELECT products.fulfillment_mode FROM products WHERE products.id = order_items.product_id)'),
            ]);

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'service_route', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'service_route', 'status']);
            $table->dropColumn('service_route');
        });
    }
};
