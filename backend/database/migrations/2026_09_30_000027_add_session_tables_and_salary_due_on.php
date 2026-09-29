<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_session_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['dining_session_id', 'dining_table_id']);
            $table->index('dining_table_id');
        });

        Schema::table('hotel_user', function (Blueprint $table) {
            $table->date('salary_due_on')->nullable()->after('pay_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_user', function (Blueprint $table) {
            $table->dropColumn('salary_due_on');
        });
        Schema::dropIfExists('dining_session_tables');
    }
};
