<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['dining_session_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['dining_session_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('dining_session_id')->references('id')->on('dining_sessions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['dining_session_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('dining_session_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('dining_session_id')->references('id')->on('dining_sessions')->restrictOnDelete();
        });
    }
};
