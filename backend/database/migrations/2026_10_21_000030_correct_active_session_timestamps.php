<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dining_sessions')
            ->whereIn('status', ['occupied', 'pending_bill'])
            ->whereNotNull('created_at')
            ->update(['opened_at' => DB::raw('created_at')]);

        DB::table('orders')
            ->whereIn('dining_session_id', function ($query) {
                $query->select('id')
                    ->from('dining_sessions')
                    ->whereIn('status', ['occupied', 'pending_bill']);
            })
            ->whereNotNull('created_at')
            ->update(['sent_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // The original database-generated timestamps cannot be reconstructed safely.
    }
};
