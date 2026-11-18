<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_money_registers', function (Blueprint $table) {
            $table->decimal('actual_online', 14, 2)->nullable()->default(null)->change();
            $table->decimal('actual_cash', 14, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('daily_money_registers')->whereNull('actual_online')->update(['actual_online' => 0]);
        DB::table('daily_money_registers')->whereNull('actual_cash')->update(['actual_cash' => 0]);

        Schema::table('daily_money_registers', function (Blueprint $table) {
            $table->decimal('actual_online', 14, 2)->default(0)->nullable(false)->change();
            $table->decimal('actual_cash', 14, 2)->default(0)->nullable(false)->change();
        });
    }
};
