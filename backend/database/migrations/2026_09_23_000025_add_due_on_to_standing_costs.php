<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standing_costs', function (Blueprint $table) {
            $table->date('due_on')->nullable()->after('pay_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('standing_costs', function (Blueprint $table) {
            $table->dropColumn('due_on');
        });
    }
};
