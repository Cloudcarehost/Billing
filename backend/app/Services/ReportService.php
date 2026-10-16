<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\StockWastage;
use App\Support\HotelDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
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

    /** @return array<string, mixed> */
    public function businessOverview(Hotel $hotel, array $filters, FinanceService $finance): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $outletId = $filters['outlet_id'] ?? null;
        $period = $filters['period'] ?? null;
        [$previousFrom, $previousTo] = HotelDate::previousRange($from, $to, is_string($period) ? $period : null);

        $current = $this->periodSnapshot($hotel, $from, $to, $outletId, $finance);
        $previous = $this->periodSnapshot($hotel, $previousFrom, $previousTo, $outletId, $finance);
        $grain = $this->overviewGrain($from, $to);
        $buckets = $this->overviewBuckets($current['days'], $grain);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'previous_period' => ['from' => $previousFrom, 'to' => $previousTo],
            'outlet_id' => $outletId,
            'expenses_hotel_wide' => $outletId !== null,
            'kpis' => [
                'gross_sales' => $this->kpi($current['gross'], $previous['gross']) + ['bills' => $current['bills']],
                'net_sales' => $this->kpi($current['net'], $previous['net']) + ['bills' => $current['bills']],
                'expenses' => $this->kpi($current['expenses'], $previous['expenses']),
                'surplus' => $this->kpi($current['net'] - $current['expenses'], $previous['net'] - $previous['expenses']),
                'discounts' => $this->kpi($current['discounts'], $previous['discounts']) + [
                    'bills' => $current['discounted_bills'],
                    'percent' => $current['gross'] > 0 ? round($current['discounts'] / $current['gross'] * 100, 1) : null,
                ],
            ],
            'series' => array_values(array_map(fn (array $bucket) => [
                'label' => $bucket['label'],
                'net_sales' => Money::fromMinor($bucket['net']),
                'expenses' => Money::fromMinor($bucket['expenses']),
            ], $buckets)),
            'expense_breakdown' => $current['breakdown'],
            'menu_performance' => $this->menuPerformance($hotel, $from, $to, $outletId),
            'payments' => $this->paymentAnalysis($hotel, $from, $to, $outletId),
            'daily' => array_values(array_map(fn (array $bucket) => [
                'label' => $bucket['label'],
                'key' => $bucket['key'],
                'bills' => $bucket['bills'],
                'gross_sales' => Money::fromMinor($bucket['gross']),
                'discounts' => Money::fromMinor($bucket['discounts']),
                'net_sales' => Money::fromMinor($bucket['net']),
                'expenses' => Money::fromMinor($bucket['expenses']),
                'surplus' => Money::fromMinor($bucket['net'] - $bucket['expenses']),
            ], $buckets)),
        ];
    }

    /** @return list<list<string>> */
    public function businessOverviewExport(Hotel $hotel, array $filters, FinanceService $finance): array
    {
        $data = $this->businessOverview($hotel, $filters, $finance);
        $rows = [
            ['Metric', 'Amount', 'Previous', 'Notes'],
            ['Gross sales', $data['kpis']['gross_sales']['amount'], $data['kpis']['gross_sales']['previous'], ''],
            ['Net sales', $data['kpis']['net_sales']['amount'], $data['kpis']['net_sales']['previous'], ''],
            ['Recorded expenses', $data['kpis']['expenses']['amount'], $data['kpis']['expenses']['previous'], $data['expenses_hotel_wide'] ? 'Hotel-wide' : ''],
            ['Estimated operating surplus', $data['kpis']['surplus']['amount'], $data['kpis']['surplus']['previous'], 'Before untracked costs'],
            ['Discounts', $data['kpis']['discounts']['amount'], $data['kpis']['discounts']['previous'], ($data['kpis']['discounts']['bills'] ?? 0).' bills'],
            [''],
            ['Category', 'Amount', 'Transactions', 'Share %'],
        ];
        foreach ($data['expense_breakdown'] as $row) {
            $rows[] = [$row['category'], $row['amount'], (string) $row['count'], (string) $row['share']];
        }
        $rows[] = [''];
        $rows[] = ['Menu category', 'Quantity sold', 'Net sales', 'Share %'];
        foreach ($data['menu_performance']['categories'] as $row) {
            $rows[] = [$row['category'], (string) $row['quantity'], $row['net_sales'], (string) $row['share']];
        }
        $rows[] = [''];
        $rows[] = ['Menu item', 'Category', 'Quantity sold', 'Net sales'];
        foreach ($data['menu_performance']['items'] as $row) {
            $rows[] = [$row['item'], $row['category'], (string) $row['quantity'], $row['net_sales']];
        }
        $rows[] = [''];
        $rows[] = ['Payment method', 'Amount collected', 'Transactions', 'Share %'];
        foreach ($data['payments']['methods'] as $row) {
            $rows[] = [$row['method'], $row['amount'], (string) $row['count'], (string) $row['share']];
        }
        $rows[] = ['Outstanding billed balance', $data['payments']['outstanding'], '', 'Issued bills still unpaid'];
        $rows[] = [''];
        $rows[] = ['Period', 'Bills', 'Gross sales', 'Discounts', 'Net sales', 'Expenses', 'Surplus'];
        foreach ($data['daily'] as $row) {
            $rows[] = [$row['label'], (string) $row['bills'], $row['gross_sales'], $row['discounts'], $row['net_sales'], $row['expenses'], $row['surplus']];
        }

        return $rows;
    }

    /**
     * @return array{gross: int, net: int, discounts: int, bills: int, discounted_bills: int, expenses: int, days: array<string, array{bills: int, gross: int, discounts: int, net: int, expenses: int}>, breakdown: list<array{category: string, amount: string, count: int, share: float}>}
     */
    private function periodSnapshot(Hotel $hotel, string $from, string $to, ?int $outletId, FinanceService $finance): array
    {
        $days = [];
        $gross = 0;
        $net = 0;
        $discounts = 0;
        $bills = 0;
        $discountedBills = 0;
        $invoices = Invoice::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereBetween('business_date', [$from, $to.' 23:59:59'])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
            ->get(['business_date', 'total_amount', 'discount_amount']);
        foreach ($invoices as $invoice) {
            $day = $invoice->business_date?->toDateString() ?? $from;
            $netMinor = Money::toMinor($invoice->total_amount);
            $discountMinor = Money::toMinor($invoice->discount_amount);
            $grossMinor = $netMinor + $discountMinor;
            $bucket = $days[$day] ?? ['bills' => 0, 'gross' => 0, 'discounts' => 0, 'net' => 0, 'expenses' => 0];
            $bucket['bills']++;
            $bucket['gross'] += $grossMinor;
            $bucket['discounts'] += $discountMinor;
            $bucket['net'] += $netMinor;
            $days[$day] = $bucket;
            $gross += $grossMinor;
            $net += $netMinor;
            $discounts += $discountMinor;
            $bills++;
            if ($discountMinor > 0) {
                $discountedBills++;
            }
        }

        $categories = [];
        $expenseTotal = 0;
        $ledger = LedgerEntry::query()
            ->where('hotel_id', $hotel->id)
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$from, $to])
            ->get(['category', 'amount', 'occurred_on']);
        foreach ($ledger as $entry) {
            $day = $entry->occurred_on instanceof \DateTimeInterface ? CarbonImmutable::parse($entry->occurred_on)->toDateString() : (string) $entry->occurred_on;
            $minor = Money::toMinor($entry->amount);
            $this->addExpenseDay($days, $day, $minor);
            $this->addBreakdown($categories, (string) $entry->category, $minor, 1);
            $expenseTotal += $minor;
        }
        foreach ($finance->standingLines($hotel, $from, $to) as $line) {
            $dates = $line['due_dates'];
            if ($dates !== []) {
                $rateMinor = Money::toMinor($line['rate']);
                foreach ($dates as $date) {
                    $this->addExpenseDay($days, $date, $rateMinor);
                    $this->addBreakdown($categories, $line['name'], $rateMinor, 1);
                    $expenseTotal += $rateMinor;
                }

                continue;
            }
            $amountMinor = Money::toMinor($line['amount']);
            $this->addExpenseDay($days, $from, $amountMinor);
            $this->addBreakdown($categories, $line['name'], $amountMinor, max(1, (int) $line['units']));
            $expenseTotal += $amountMinor;
        }

        uasort($categories, fn (array $left, array $right) => $right['amount'] <=> $left['amount']);
        $breakdown = [];
        foreach ($categories as $name => $row) {
            $breakdown[] = [
                'category' => $name,
                'amount' => Money::fromMinor($row['amount']),
                'count' => $row['count'],
                'share' => $expenseTotal > 0 ? round($row['amount'] / $expenseTotal * 100, 1) : 0.0,
            ];
        }

        return [
            'gross' => $gross,
            'net' => $net,
            'discounts' => $discounts,
            'bills' => $bills,
            'discounted_bills' => $discountedBills,
            'expenses' => $expenseTotal,
            'days' => $days,
            'breakdown' => $breakdown,
        ];
    }

    /** @param  array<string, array{bills: int, gross: int, discounts: int, net: int, expenses: int}>  $days */
    private function addExpenseDay(array &$days, string $day, int $minor): void
    {
        $bucket = $days[$day] ?? ['bills' => 0, 'gross' => 0, 'discounts' => 0, 'net' => 0, 'expenses' => 0];
        $bucket['expenses'] += $minor;
        $days[$day] = $bucket;
    }

    /** @param  array<string, array{amount: int, count: int}>  $categories */
    private function addBreakdown(array &$categories, string $name, int $minor, int $count): void
    {
        $label = trim($name) !== '' ? trim($name) : 'Other';
        $current = $categories[$label] ?? ['amount' => 0, 'count' => 0];
        $current['amount'] += $minor;
        $current['count'] += $count;
        $categories[$label] = $current;
    }

    /**
     * @return array{categories: list<array{category: string, quantity: float, net_sales: string, share: float}>, items: list<array{item: string, category: string, quantity: float, net_sales: string}>}
     */
    private function menuPerformance(Hotel $hotel, string $from, string $to, ?int $outletId): array
    {
        $rows = InvoiceItem::query()
            ->select([
                'invoice_items.item_name',
                'invoice_items.quantity',
                'invoice_items.line_total',
                'invoice_items.category_name',
                'categories.name as product_category',
            ])
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('invoices.hotel_id', $hotel->id)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereBetween('invoices.business_date', [$from, $to.' 23:59:59'])
            ->when($outletId, fn ($query) => $query->where('invoices.outlet_id', $outletId))
            ->get();

        $categories = [];
        $items = [];
        $salesTotal = 0;
        foreach ($rows as $row) {
            $category = trim((string) ($row->category_name ?: $row->product_category ?: 'Uncategorised'));
            $item = trim((string) $row->item_name) !== '' ? trim((string) $row->item_name) : 'Item';
            $qty = (float) $row->quantity;
            $salesMinor = Money::toMinor($row->line_total);
            $salesTotal += $salesMinor;
            $cat = $categories[$category] ?? ['quantity' => 0.0, 'sales' => 0];
            $cat['quantity'] += $qty;
            $cat['sales'] += $salesMinor;
            $categories[$category] = $cat;
            $itemKey = $item."\n".$category;
            $line = $items[$itemKey] ?? ['item' => $item, 'category' => $category, 'quantity' => 0.0, 'sales' => 0];
            $line['quantity'] += $qty;
            $line['sales'] += $salesMinor;
            $items[$itemKey] = $line;
        }

        uasort($categories, fn (array $left, array $right) => $right['sales'] <=> $left['sales']);
        uasort($items, fn (array $left, array $right) => $right['sales'] <=> $left['sales']);

        $categoryRows = [];
        foreach ($categories as $name => $row) {
            $categoryRows[] = [
                'category' => $name,
                'quantity' => $this->formatQuantity($row['quantity']),
                'net_sales' => Money::fromMinor($row['sales']),
                'share' => $salesTotal > 0 ? round($row['sales'] / $salesTotal * 100, 1) : 0.0,
            ];
        }
        $itemRows = [];
        foreach ($items as $row) {
            $itemRows[] = [
                'item' => $row['item'],
                'category' => $row['category'],
                'quantity' => $this->formatQuantity($row['quantity']),
                'net_sales' => Money::fromMinor($row['sales']),
            ];
        }

        return [
            'categories' => $categoryRows,
            'items' => $itemRows,
        ];
    }

    /**
     * @return array{collected: string, outstanding: string, methods: list<array{method: string, amount: string, count: int, share: float}>}
     */
    private function paymentAnalysis(Hotel $hotel, string $from, string $to, ?int $outletId): array
    {
        $invoiceQuery = Invoice::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereBetween('business_date', [$from, $to.' 23:59:59'])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId));
        $outstanding = Money::fromMinor(Money::toMinor((clone $invoiceQuery)->sum('balance_amount')));
        $payments = Payment::query()
            ->select(['payments.method', 'payments.type', 'payments.amount'])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.hotel_id', $hotel->id)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereBetween('invoices.business_date', [$from, $to.' 23:59:59'])
            ->when($outletId, fn ($query) => $query->where('invoices.outlet_id', $outletId))
            ->get();

        $methods = [];
        $collectedMinor = 0;
        foreach ($payments as $payment) {
            $method = strtolower((string) $payment->method);
            $minor = Money::toMinor($payment->amount);
            $current = $methods[$method] ?? ['amount' => 0, 'count' => 0];
            $current['amount'] += $minor;
            if ($payment->type === PaymentType::Payment->value) {
                $current['count']++;
            }
            $methods[$method] = $current;
            $collectedMinor += $minor;
        }
        uasort($methods, fn (array $left, array $right) => $right['amount'] <=> $left['amount']);
        $methodRows = [];
        foreach ($methods as $method => $row) {
            $methodRows[] = [
                'method' => $method,
                'amount' => Money::fromMinor($row['amount']),
                'count' => $row['count'],
                'share' => $collectedMinor > 0 ? round($row['amount'] / $collectedMinor * 100, 1) : 0.0,
            ];
        }

        return [
            'collected' => Money::fromMinor($collectedMinor),
            'outstanding' => $outstanding,
            'methods' => $methodRows,
        ];
    }

    private function formatQuantity(float $quantity): float
    {
        return round($quantity, 3);
    }

    /** @return array{amount: string, previous: string, change_percent: float|null} */
    private function kpi(int $current, int $previous): array
    {
        return [
            'amount' => Money::fromMinor($current),
            'previous' => Money::fromMinor($previous),
            'change_percent' => $previous === 0 ? null : round(($current - $previous) / abs($previous) * 100, 1),
        ];
    }

    private function overviewGrain(string $from, string $to): string
    {
        $span = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        if ($span <= 31) {
            return 'day';
        }

        return $span <= 120 ? 'week' : 'month';
    }

    /**
     * @param  array<string, array{bills: int, gross: int, discounts: int, net: int, expenses: int}>  $days
     * @return array<string, array{key: string, label: string, bills: int, gross: int, discounts: int, net: int, expenses: int}>
     */
    private function overviewBuckets(array $days, string $grain): array
    {
        $buckets = [];
        foreach ($days as $date => $row) {
            $key = $this->bucketKey($date, $grain);
            $bucket = $buckets[$key] ?? ['key' => $key, 'label' => $this->bucketLabel($key, $grain), 'bills' => 0, 'gross' => 0, 'discounts' => 0, 'net' => 0, 'expenses' => 0];
            $bucket['bills'] += $row['bills'];
            $bucket['gross'] += $row['gross'];
            $bucket['discounts'] += $row['discounts'];
            $bucket['net'] += $row['net'];
            $bucket['expenses'] += $row['expenses'];
            $buckets[$key] = $bucket;
        }
        ksort($buckets);

        return $buckets;
    }

    private function bucketKey(string $date, string $grain): string
    {
        $day = CarbonImmutable::parse($date);

        return match ($grain) {
            'week' => $day->startOfWeek()->toDateString(),
            'month' => $day->format('Y-m'),
            default => $day->toDateString(),
        };
    }

    private function bucketLabel(string $key, string $grain): string
    {
        if ($grain === 'month') {
            return CarbonImmutable::parse($key.'-01')->format('M Y');
        }
        if ($grain === 'week') {
            $start = CarbonImmutable::parse($key);

            return $start->format('d M').'–'.$start->endOfWeek()->format('d M');
        }

        return CarbonImmutable::parse($key)->format('d M Y');
    }
}
