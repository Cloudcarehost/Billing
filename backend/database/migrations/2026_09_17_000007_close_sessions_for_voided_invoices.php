<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sessionIds = DB::table('invoices')
            ->where('status', 'voided')
            ->whereNotNull('dining_session_id')
            ->pluck('dining_session_id');

        if ($sessionIds->isNotEmpty()) {
            DB::table('dining_sessions')
                ->whereIn('id', $sessionIds)
                ->whereIn('status', ['occupied', 'pending_bill'])
                ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // A closed service session must not be reopened automatically on rollback.
    }
};
