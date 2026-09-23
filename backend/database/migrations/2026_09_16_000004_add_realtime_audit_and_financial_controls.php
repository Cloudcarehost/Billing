<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->boolean('allow_negative_stock')->default(false)->after('is_active');
            $table->string('inventory_deduction_rule', 20)->default('accepted')->after('allow_negative_stock');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('accepted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('accepted_by')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('notes');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('accepted_by')->nullable()->after('order_id')->constrained('users')->nullOnDelete();
            $table->foreignId('preparing_by')->nullable()->after('accepted_by')->constrained('users')->nullOnDelete();
            $table->foreignId('ready_by')->nullable()->after('preparing_by')->constrained('users')->nullOnDelete();
            $table->foreignId('served_by')->nullable()->after('ready_by')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('served_by')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('kitchen_note');
            $table->timestamp('accepted_at')->nullable()->after('cancelled_at');
            $table->boolean('inventory_deducted')->default(false)->after('accepted_at');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('reprint_count')->default(0)->after('void_reason');
            $table->timestamp('last_reprinted_at')->nullable()->after('reprint_count');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('reference_number');
            $table->foreignId('refunded_payment_id')->nullable()->after('idempotency_key')->constrained('payments')->nullOnDelete();
            $table->index(['invoice_id', 'idempotency_key']);
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['hotel_id', 'occurred_at']);
            $table->index(['outlet_id', 'action', 'occurred_at']);
        });
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('scope', 50);
            $table->json('response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('activity_logs');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_payment_id');
            $table->dropIndex(['invoice_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['reprint_count', 'last_reprinted_at']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropConstrainedForeignId('preparing_by');
            $table->dropConstrainedForeignId('ready_by');
            $table->dropConstrainedForeignId('served_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancel_reason', 'accepted_at', 'inventory_deducted']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancel_reason');
        });
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['allow_negative_stock', 'inventory_deduction_rule']);
        });
    }
};
