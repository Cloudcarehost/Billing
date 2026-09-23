<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->string('service_type', 20)->default('dine_in')->after('is_active');
            $table->index(['outlet_id', 'service_type', 'is_active']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('charged_to_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charged_to_user_id');
        });
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'service_type', 'is_active']);
            $table->dropColumn('service_type');
        });
    }
};
