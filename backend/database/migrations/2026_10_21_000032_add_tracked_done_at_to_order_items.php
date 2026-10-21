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
            $table->timestamp('tracked_done_at')->nullable()->after('served_at');
            $table->index(['order_id', 'service_route', 'tracked_done_at']);
        });

        DB::table('order_items')->whereIn('order_id', fn ($query) => $query->select('id')->from('orders')->whereNotNull('tracked_done_at'))
            ->update(['tracked_done_at' => DB::raw('(SELECT orders.tracked_done_at FROM orders WHERE orders.id = order_items.order_id)')]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'service_route', 'tracked_done_at']);
            $table->dropColumn('tracked_done_at');
        });
    }
};
