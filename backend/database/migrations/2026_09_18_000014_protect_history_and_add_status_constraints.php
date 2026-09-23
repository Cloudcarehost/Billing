<?php

use App\Enums\DiningSessionStatus;
use App\Enums\FulfillmentMode;
use App\Enums\IdempotencyStatus;
use App\Enums\InventoryDeductionRule;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\TaxType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('gstin', 20)->nullable()->after('tax_number');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->string('customer_gstin', 20)->nullable()->after('customer_email');
        });
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->string('category_name')->nullable()->after('item_name');
            $table->string('serving_size', 40)->nullable()->after('unit');
            $table->string('tax_type', 20)->nullable()->after('tax_rate');
        });
        $this->backfillInvoiceSnapshots();

        $this->restrictDelete('invoices', 'hotel_id', 'hotels');
        $this->restrictDelete('invoice_items', 'invoice_id', 'invoices');
        $this->restrictDelete('payments', 'invoice_id', 'invoices');
        $this->restrictDelete('orders', 'dining_session_id', 'dining_sessions');
        $this->restrictDelete('order_items', 'order_id', 'orders');
        $this->restrictDelete('stock_movements', 'inventory_stock_id', 'inventory_stocks');
        $this->restrictDelete('activity_logs', 'hotel_id', 'hotels');

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['hotel_id', 'action', 'occurred_at'], 'activity_logs_hotel_action_occurred_idx');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['type', 'paid_at'], 'payments_type_paid_at_idx');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['inventory_stock_id', 'type', 'occurred_at'], 'stock_movements_stock_type_occurred_idx');
        });
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index('expires_at', 'idempotency_keys_expires_at_idx');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['status', 'cancelled_at'], 'order_items_status_cancelled_idx');
        });
        Schema::table('table_public_links', function (Blueprint $table): void {
            $table->index(['is_active', 'token_hash'], 'table_public_links_active_token_idx');
        });
        Schema::table('dining_sessions', function (Blueprint $table): void {
            $table->index(['status', 'outlet_id', 'opened_at'], 'dining_sessions_status_outlet_opened_idx');
        });

        $this->check('dining_sessions', 'status', DiningSessionStatus::values());
        $this->check('orders', 'status', OrderStatus::values());
        $this->check('order_items', 'status', OrderItemStatus::values());
        $this->check('order_items', 'fulfillment_mode', FulfillmentMode::values());
        $this->check('products', 'fulfillment_mode', FulfillmentMode::values());
        $this->check('invoices', 'status', InvoiceStatus::values());
        $this->check('invoices', 'payment_status', PaymentStatus::values());
        $this->check('payments', 'type', PaymentType::values());
        $this->check('payments', 'method', PaymentMethod::values());
        $this->check('stock_movements', 'type', InventoryMovementType::values());
        $this->check('idempotency_keys', 'status', IdempotencyStatus::values());
        $this->check('hotels', 'inventory_deduction_rule', InventoryDeductionRule::values());
        $this->check('invoice_items', 'tax_type', TaxType::values(), nullable: true);
    }

    public function down(): void
    {
        foreach ([
            'dining_sessions_status_check', 'orders_status_check', 'order_items_status_check', 'order_items_fulfillment_mode_check',
            'products_fulfillment_mode_check', 'invoices_status_check', 'invoices_payment_status_check', 'payments_type_check',
            'payments_method_check', 'stock_movements_type_check', 'idempotency_keys_status_check',
            'hotels_inventory_deduction_rule_check', 'invoice_items_tax_type_check',
        ] as $constraint) {
            $this->dropCheck($constraint);
        }

        Schema::table('activity_logs', fn (Blueprint $table) => $table->dropIndex('activity_logs_hotel_action_occurred_idx'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_type_paid_at_idx'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropIndex('stock_movements_stock_type_occurred_idx'));
        Schema::table('idempotency_keys', fn (Blueprint $table) => $table->dropIndex('idempotency_keys_expires_at_idx'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropIndex('order_items_status_cancelled_idx'));
        Schema::table('table_public_links', fn (Blueprint $table) => $table->dropIndex('table_public_links_active_token_idx'));
        Schema::table('dining_sessions', fn (Blueprint $table) => $table->dropIndex('dining_sessions_status_outlet_opened_idx'));

        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['category_name', 'serving_size', 'tax_type']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['customer_email', 'customer_gstin']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('gstin'));
    }

    private function restrictDelete(string $table, string $column, string $parent): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent): void {
            $blueprint->foreign($column)->references('id')->on($parent)->restrictOnDelete();
        });
    }

    /** @param list<string> $values */
    private function check(string $table, string $column, array $values, bool $nullable = false): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }
        $list = collect($values)->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")->implode(',');
        $expression = $nullable
            ? "{$column} IS NULL OR {$column} IN ({$list})"
            : "{$column} IN ({$list})";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$expression})");
    }

    private function dropCheck(string $name): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }
        $table = str($name)->beforeLast('_check')->beforeLast('_')->value();
        // Constraint names are {table}_{column}_check; table can contain underscores.
        $parts = explode('_', $name);
        array_pop($parts);
        // Best-effort: MySQL 8.0.19+ DROP CHECK
        try {
            DB::statement("ALTER TABLE {$this->tableFromConstraint($name)} DROP CHECK {$name}");
        } catch (\Throwable) {
            // Driver may use a different syntax; down() is best-effort for production MySQL.
        }
    }

    private function tableFromConstraint(string $name): string
    {
        $known = [
            'dining_sessions_status_check' => 'dining_sessions',
            'orders_status_check' => 'orders',
            'order_items_status_check' => 'order_items',
            'order_items_fulfillment_mode_check' => 'order_items',
            'products_fulfillment_mode_check' => 'products',
            'invoices_status_check' => 'invoices',
            'invoices_payment_status_check' => 'invoices',
            'payments_type_check' => 'payments',
            'payments_method_check' => 'payments',
            'stock_movements_type_check' => 'stock_movements',
            'idempotency_keys_status_check' => 'idempotency_keys',
            'hotels_inventory_deduction_rule_check' => 'hotels',
            'invoice_items_tax_type_check' => 'invoice_items',
        ];

        return $known[$name] ?? 'invoices';
    }

    private function backfillInvoiceSnapshots(): void
    {
        $items = DB::table('invoice_items')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                'invoice_items.id',
                'categories.name as category_name',
                'products.serving_size',
                'products.price_includes_tax',
            ])
            ->get();
        foreach ($items as $item) {
            DB::table('invoice_items')->where('id', $item->id)->update([
                'category_name' => $item->category_name,
                'serving_size' => $item->serving_size,
                'tax_type' => $item->price_includes_tax ? TaxType::Inclusive->value : TaxType::Exclusive->value,
            ]);
        }
    }
};
