<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'phone']);
            $table->index(['hotel_id', 'name']);
        });

        Schema::create('dining_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('dining_table_id')->constrained()->restrictOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->string('status', 20)->default('occupied');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('service_charge_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['dining_table_id', 'status']);
            $table->index(['outlet_id', 'status', 'opened_at']);
            $table->index(['waiter_id', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ticket_number', 60)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('round_number')->default(1);
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['dining_session_id', 'round_number']);
            $table->index(['status', 'sent_at']);
            $table->index(['dining_session_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kitchen_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->string('sku', 80)->nullable();
            $table->string('unit', 20)->default('item');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->string('status', 20)->default('pending');
            $table->text('kitchen_note')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['kitchen_station_id', 'status', 'created_at']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('period', 20);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['outlet_id', 'period']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dining_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_number', 60);
            $table->string('status', 20)->default('draft');
            $table->string('payment_status', 20)->default('unpaid');
            $table->date('business_date');
            $table->timestamp('billed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('service_charge_amount', 14, 2)->default(0);
            $table->decimal('rounding_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('balance_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'invoice_number']);
            $table->unique('dining_session_id');
            $table->index(['hotel_id', 'business_date', 'status']);
            $table->index(['outlet_id', 'business_date', 'status']);
            $table->index(['hotel_id', 'payment_status', 'business_date']);
            $table->index(['created_by', 'billed_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->string('sku', 80)->nullable();
            $table->string('unit', 20)->default('item');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index(['invoice_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('payment');
            $table->string('method', 30);
            $table->decimal('amount', 14, 2);
            $table->string('reference_number', 100)->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_at']);
            $table->index(['method', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('dining_sessions');
        Schema::dropIfExists('customers');
    }
};
