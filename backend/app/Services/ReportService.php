<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\StockWastage;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReportService
{
    public static function invalidateDashboard(int $hotelId): void
    {
        $key = "reports:dashboard-version:{$hotelId}";
        Cache::forever($key, (int) Cache::get($key, 0) + 1);
    }

    public function dashboard(Hotel $hotel, array $filters): array
    {
        $from = $filters['from'] ?? now($hotel->timezone)->toDateString();
        $to = $filters['to'] ?? $from;
        $outletId = $filters['outlet_id'] ?? null;
        $version = Cache::get("reports:dashboard-version:{$hotel->id}", 0);

        return Cache::remember("reports:dashboard:{$hotel->id}:{$version}:{$outletId}:{$from}:{$to}", now()->addMinutes(3), function () use ($hotel, $from, $to, $outletId) {
            $invoices = Invoice::query()->where('invoices.hotel_id', $hotel->id)->whereBetween('invoices.business_date', [$from, $to.' 23:59:59'])->where('invoices.status', 'issued')->when($outletId, fn ($query) => $query->where('invoices.outlet_id', $outletId));
            $totals = (clone $invoices)->selectRaw('COUNT(*) as bills, COALESCE(SUM(total_amount), 0) as sales, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(discount_amount), 0) as discount, SUM(CASE WHEN discount_amount > 0 THEN 1 ELSE 0 END) as discounted_bills, COALESCE(SUM((subtotal - discount_amount) - (SELECT COALESCE(SUM(invoice_items.unit_cost * invoice_items.quantity), 0) FROM invoice_items WHERE invoice_items.invoice_id = invoices.id)), 0) as gross_profit')->first();
            $paymentTotals = (clone $invoices)->join('payments', 'payments.invoice_id', '=', 'invoices.id')->where('payments.type', 'payment')->selectRaw('payments.method, SUM(payments.amount) as amount')->groupBy('payments.method')->pluck('amount', 'method');
            $topItems = (clone $invoices)->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')->selectRaw('invoice_items.item_name, SUM(invoice_items.quantity) as quantity, SUM(invoice_items.line_total) as sales')->groupBy('invoice_items.item_name')->orderByDesc('sales')->limit(8)->get();
            $categorySales = (clone $invoices)->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')->leftJoin('categories', 'categories.id', '=', 'products.category_id')->selectRaw("COALESCE(invoice_items.category_name, categories.name, 'Uncategorised') as category, SUM(invoice_items.line_total) as sales")->groupBy('category')->orderByDesc('sales')->get();
            $waiterSales = (clone $invoices)->leftJoin('dining_sessions', 'dining_sessions.id', '=', 'invoices.dining_session_id')->leftJoin('users', 'users.id', '=', 'dining_sessions.waiter_id')->selectRaw("COALESCE(users.name, 'Counter') as waiter, SUM(invoices.total_amount) as sales, COUNT(invoices.id) as bills")->groupBy('waiter')->orderByDesc('sales')->get();
            $lowStock = InventoryStock::query()->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))->whereColumn('quantity', '<=', 'reorder_level')->with('product:id,name,sku,unit', 'outlet:id,name')->limit(10)->get();
            $pending = $hotel->diningSessions()->where('status', 'pending_bill')->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))->count();
            $tableQuery = DiningTable::query()
                ->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))
                ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
                ->where(fn ($query) => $query->where('service_type', 'dine_in')->orWhereNull('service_type'));
            $tableCount = (clone $tableQuery)->count();
            $occupiedTableCount = $hotel->diningSessions()
                ->whereIn('status', ['occupied', 'pending_bill'])
                ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
                ->whereHas('diningTable', fn ($query) => $query->where('service_type', 'dine_in')->orWhereNull('service_type'))
                ->count();

            return ['period' => compact('from', 'to'), 'sales' => ['amount' => (string) $totals->sales, 'bills' => (int) $totals->bills, 'average_bill' => $totals->bills ? Money::fromMinor((int) round(Money::toMinor($totals->sales) / $totals->bills)) : Money::fromMinor(0), 'tax_collected' => (string) $totals->tax, 'gross_profit_estimate' => (string) $totals->gross_profit], 'discounts' => ['amount' => Money::fromMinor(Money::toMinor($totals->discount ?? 0)), 'bills' => (int) $totals->discounted_bills], 'payment_totals' => $paymentTotals, 'pending_bills' => $pending, 'tables' => ['total' => $tableCount, 'available' => max(0, $tableCount - $occupiedTableCount), 'occupied' => $occupiedTableCount], 'top_items' => $topItems, 'category_sales' => $categorySales, 'waiter_sales' => $waiterSales, 'low_stock' => $lowStock, 'voided_bills' => Invoice::query()->where('hotel_id', $hotel->id)->whereBetween('business_date', [$from, $to.' 23:59:59'])->where('status', 'voided')->count(), 'cancelled_items' => OrderItem::query()->where('status', 'cancelled')->whereHas('order.diningSession', fn ($query) => $query->where('hotel_id', $hotel->id)->whereBetween('opened_at', [$from.' 00:00:00', $to.' 23:59:59']))->count(), 'wastage' => StockWastage::query()->where('hotel_id', $hotel->id)->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('quantity')];
        });
    }

    public function popularProducts(Hotel $hotel, int $outletId, int $days = 14, int $limit = 8): array
    {
        $days = max(1, min($days, 90));
        $limit = max(1, min($limit, 12));
        $version = Cache::get("reports:dashboard-version:{$hotel->id}", 0);
        $timezone = $hotel->timezone ?: 'Asia/Kolkata';
        $to = now($timezone)->toDateString();
        $from = now($timezone)->subDays($days - 1)->toDateString();

        return Cache::remember("products:popular:{$hotel->id}:{$version}:{$outletId}:{$days}:{$limit}", now()->addMinutes(10), function () use ($hotel, $outletId, $from, $to, $limit) {
            return Invoice::query()
                ->where('invoices.hotel_id', $hotel->id)
                ->where('invoices.outlet_id', $outletId)
                ->where('invoices.status', InvoiceStatus::Issued->value)
                ->whereBetween('invoices.business_date', [$from, $to.' 23:59:59'])
                ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->whereNotNull('invoice_items.product_id')
                ->selectRaw('invoice_items.product_id, SUM(invoice_items.quantity) as quantity')
                ->groupBy('invoice_items.product_id')
                ->orderByDesc('quantity')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => ['product_id' => (int) $row->product_id, 'quantity' => (float) $row->quantity])
                ->values()
                ->all();
        });
    }

    public function invoiceRegister(Hotel $hotel, array $filters): Collection
    {
        return Invoice::query()->where('hotel_id', $hotel->id)->whereBetween('business_date', [$filters['from'], $filters['to'].' 23:59:59'])->when($filters['outlet_id'] ?? null, fn ($query, $id) => $query->where('outlet_id', $id))->with('outlet:id,name,code', 'customer:id,name,phone', 'chargedTo:id,name')->orderByDesc('business_date')->orderByDesc('id')->get();
    }
}
