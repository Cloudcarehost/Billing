<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_sessions', function (Blueprint $table) {
            $table->decimal('discount_percent', 8, 4)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('dining_sessions', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
