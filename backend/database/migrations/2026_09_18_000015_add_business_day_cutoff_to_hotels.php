<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->time('business_day_starts_at')->default('05:00:00')->after('timezone');
            $table->date('last_ended_business_date')->nullable()->after('business_day_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['business_day_starts_at', 'last_ended_business_date']);
        });
    }
};
