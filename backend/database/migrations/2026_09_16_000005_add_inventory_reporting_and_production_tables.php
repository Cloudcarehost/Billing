<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('gstin', 20)->nullable()->after('tax_number');
            $table->string('state_code', 2)->nullable()->after('gstin');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('hsn_code', 20)->nullable()->after('barcode');
            $table->index(['hotel_id', 'hsn_code']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('cgst_amount', 14, 2)->default(0)->after('tax_amount');
            $table->decimal('sgst_amount', 14, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 14, 2)->default(0)->after('sgst_amount');
            $table->string('place_of_supply', 2)->nullable()->after('customer_phone');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('hsn_code', 20)->nullable()->after('sku');
            $table->decimal('cgst_amount', 14, 2)->default(0)->after('tax_amount');
            $table->decimal('sgst_amount', 14, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 14, 2)->default(0)->after('sgst_amount');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'is_active', 'name']);
        });
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_number', 60);
            $table->string('supplier_invoice_number', 80)->nullable();
            $table->date('received_on');
            $table->string('status', 20)->default('received');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['outlet_id', 'receipt_number']);
            $table->index(['hotel_id', 'received_on', 'status']);
        });
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
        });
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('to_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transfer_number', 60);
            $table->string('status', 20)->default('draft');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'transfer_number']);
            $table->index(['from_outlet_id', 'status', 'created_at']);
            $table->index(['to_outlet_id', 'status', 'created_at']);
        });
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'product_id']);
        });
        Schema::create('stock_wastages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->string('reason', 255);
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['outlet_id', 'occurred_at']);
        });
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['outlet_id', 'status', 'created_at']);
        });
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3);
            $table->decimal('variance_quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_id']);
        });
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('yield_quantity', 14, 3)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('product_id');
        });
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
            $table->unique(['recipe_id', 'ingredient_product_id']);
        });
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->json('filters')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'status', 'created_at']);
        });
        Schema::create('user_device_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 120)->unique();
            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_sessions');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_wastages');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('suppliers');
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['hsn_code', 'cgst_amount', 'sgst_amount', 'igst_amount']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply']));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'hsn_code']);
            $table->dropColumn('hsn_code');
        });
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn(['gstin', 'state_code']));
    }
};
