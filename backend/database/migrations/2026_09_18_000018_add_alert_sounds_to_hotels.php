<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('alert_sound_kitchen', 16)->default('chirp');
            $table->string('alert_sound_ready', 16)->default('waiter');
            $table->string('alert_sound_call', 16)->default('waiter');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['alert_sound_kitchen', 'alert_sound_ready', 'alert_sound_call']);
        });
    }
};
