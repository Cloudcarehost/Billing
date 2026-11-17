<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'status', 'opened_at']);
            $table->index(['outlet_id', 'status', 'opened_at']);
            $table->index(['assigned_to_user_id', 'status']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('replaces_invoice_id')->nullable()->after('charged_to_user_id')->constrained('invoices')->nullOnDelete();
        });

        Schema::table('dining_sessions', function (Blueprint $table): void {
            $table->foreignId('correction_of_invoice_id')->nullable()->after('customer_id')->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dining_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_of_invoice_id');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaces_invoice_id');
        });
        Schema::dropIfExists('invoice_receivables');
    }
};
