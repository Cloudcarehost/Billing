<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'slug']);
            $table->index(['hotel_id', 'is_active', 'sort_order']);
        });

        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
            $table->index(['outlet_id', 'is_active']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kitchen_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 80)->nullable();
            $table->string('barcode', 80)->nullable();
            $table->string('unit', 20)->default('item');
            $table->decimal('selling_price', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->boolean('price_includes_tax')->default(false);
            $table->boolean('track_inventory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['hotel_id', 'sku']);
            $table->unique(['hotel_id', 'barcode']);
            $table->index(['hotel_id', 'is_active', 'category_id']);
            $table->index(['hotel_id', 'name']);
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('average_cost', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'product_id']);
            $table->index(['outlet_id', 'quantity']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->nullableMorphs('reference');
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['inventory_stock_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('kitchen_stations');
        Schema::dropIfExists('categories');
    }
};
