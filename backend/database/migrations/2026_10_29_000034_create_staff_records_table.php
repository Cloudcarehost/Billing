<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('record_type', 30);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('leave_duration', 20)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency_code_snapshot', 3)->nullable();
            $table->string('staff_name_snapshot');
            $table->string('role_name_snapshot', 80)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'start_date', 'record_type']);
            $table->index(['hotel_id', 'user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_records');
    }
};
