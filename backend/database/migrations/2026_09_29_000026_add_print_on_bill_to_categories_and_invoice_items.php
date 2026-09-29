<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('print_on_bill')->default(true)->after('is_active');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('print_on_bill')->default(true)->after('category_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('print_on_bill');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('print_on_bill');
        });
    }
};
