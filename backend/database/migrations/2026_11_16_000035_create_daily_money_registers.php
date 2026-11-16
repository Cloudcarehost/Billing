<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('hotel_id')->constrained()->nullOnDelete();
            $table->index(['outlet_id', 'occurred_on', 'type']);
        });

        $primaryOutlets = DB::table('outlets')
            ->selectRaw('hotel_id, MIN(id) as outlet_id')
            ->groupBy('hotel_id')
            ->pluck('outlet_id', 'hotel_id');
        foreach ($primaryOutlets as $hotelId => $outletId) {
            DB::table('ledger_entries')
                ->where('hotel_id', $hotelId)
                ->whereNull('outlet_id')
                ->update(['outlet_id' => $outletId]);
        }

        Schema::create('daily_money_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('actual_online', 14, 2)->default(0);
            $table->decimal('actual_cash', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date']);
            $table->index(['hotel_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_money_registers');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'occurred_on', 'type']);
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
