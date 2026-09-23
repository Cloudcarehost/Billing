<?php

use App\Models\Hotel;
use App\Services\RolePresetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_user', function (Blueprint $table) {
            $table->decimal('salary_amount', 14, 2)->nullable();
            $table->string('pay_cycle', 20)->nullable();
        });

        Schema::create('standing_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('amount', 14, 2);
            $table->string('pay_cycle', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['hotel_id', 'is_active']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('category', 80);
            $table->decimal('amount', 14, 2);
            $table->text('comment')->nullable();
            $table->date('occurred_on');
            $table->timestamps();
            $table->index(['hotel_id', 'occurred_on', 'type']);
        });

        $presets = app(RolePresetService::class);
        foreach (Hotel::query()->get() as $hotel) {
            $presets->createDefaults($hotel);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('standing_costs');
        Schema::table('hotel_user', function (Blueprint $table) {
            $table->dropColumn(['salary_amount', 'pay_cycle']);
        });
    }
};
