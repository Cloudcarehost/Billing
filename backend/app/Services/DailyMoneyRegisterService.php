<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
use App\Models\DailyMoneyRegister;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Support\HotelDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class DailyMoneyRegisterService
{
    /** @return array<string, mixed> */
    public function overview(Hotel $hotel, int $outletId, string $from, string $to): array
    {
        $today = HotelDate::businessDate($hotel);
        $effectiveTo = CarbonImmutable::parse($to)->greaterThan(CarbonImmutable::parse($today)) ? $today : $to;
        $collections = [];
        foreach ($this->payments($hotel, $outletId, $from, $effectiveTo) as $payment) {
            $date = HotelDate::businessDate($hotel, $payment->paid_at);
            $collections[$date] = ($collections[$date] ?? 0) + Money::toMinor($payment->amount);
        }

        $ledger = LedgerEntry::query()
            ->where('hotel_id', $hotel->id)
            ->where('outlet_id', $outletId)
            ->whereBetween('occurred_on', [$from, $effectiveTo.' 23:59:59'])
            ->selectRaw('occurred_on, type, SUM(amount) as amount')
            ->groupBy('occurred_on', 'type')
            ->get();
        $expenses = [];
        $income = [];
        foreach ($ledger as $entry) {
            $date = CarbonImmutable::parse($entry->occurred_on)->toDateString();
            if ($entry->type === 'income') {
                $income[$date] = ($income[$date] ?? 0) + Money::toMinor($entry->amount);
            } else {
                $expenses[$date] = ($expenses[$date] ?? 0) + Money::toMinor($entry->amount);
            }
        }

        $registers = DailyMoneyRegister::query()
            ->where('hotel_id', $hotel->id)
            ->where('outlet_id', $outletId)
            ->whereBetween('business_date', [$from, $effectiveTo.' 23:59:59'])
            ->with(['creator:id,name', 'updater:id,name'])
            ->get()
            ->keyBy(fn (DailyMoneyRegister $register) => $register->business_date->toDateString());
        $prior = DailyMoneyRegister::query()
            ->where('hotel_id', $hotel->id)
            ->where('outlet_id', $outletId)
            ->whereDate('business_date', '<', $from)
            ->latest('business_date')
            ->first();
        $carriedTotal = $prior
            ? Money::toMinor($prior->actual_online ?? 0) + Money::toMinor($prior->actual_cash ?? 0)
            : 0;

        $rows = [];
        $periodCollections = 0;
        $periodExpenses = 0;
        $periodIncome = 0;
        $cursor = CarbonImmutable::parse($from)->startOfDay();
        $last = CarbonImmutable::parse($effectiveTo)->startOfDay();
        while ($cursor->lessThanOrEqualTo($last)) {
            $date = $cursor->toDateString();
            $received = (int) ($collections[$date] ?? 0);
            $expense = (int) ($expenses[$date] ?? 0);
            $otherIncome = (int) ($income[$date] ?? 0);
            $expected = $carriedTotal + $received + $otherIncome - $expense;
            /** @var DailyMoneyRegister|null $register */
            $register = $registers->get($date);
            $actualOnline = $register?->actual_online !== null ? Money::toMinor($register->actual_online) : null;
            $actualCash = $register?->actual_cash !== null ? Money::toMinor($register->actual_cash) : null;
            $actualTotal = $register ? ($actualOnline ?? 0) + ($actualCash ?? 0) : null;
            $completeBalance = $actualOnline !== null && $actualCash !== null;
            $difference = $completeBalance ? $actualTotal - $expected : null;

            $rows[] = [
                'business_date' => $date,
                'yesterday_total' => Money::fromMinor($carriedTotal),
                'collections' => Money::fromMinor($received),
                'expenses' => Money::fromMinor($expense),
                'other_income' => Money::fromMinor($otherIncome),
                'expected_total' => Money::fromMinor($expected),
                'register_id' => $register?->id,
                'actual_online' => $actualOnline === null ? null : Money::fromMinor($actualOnline),
                'actual_cash' => $actualCash === null ? null : Money::fromMinor($actualCash),
                'actual_total' => $actualTotal === null ? null : Money::fromMinor($actualTotal),
                'difference' => $difference === null ? null : Money::fromMinor($difference),
                'status' => ! $register ? 'not_entered' : (! $completeBalance ? 'partial' : ($difference === 0 ? 'matched' : ($difference < 0 ? 'short' : 'over'))),
                'notes' => $register?->notes,
                'created_by' => $register?->creator ? ['id' => $register->creator->id, 'name' => $register->creator->name] : null,
                'updated_by' => $register?->updater ? ['id' => $register->updater->id, 'name' => $register->updater->name] : null,
            ];

            $periodCollections += $received;
            $periodExpenses += $expense;
            $periodIncome += $otherIncome;
            $carriedTotal = $completeBalance ? $actualTotal : $expected;
            $cursor = $cursor->addDay();
        }

        $latest = $registers->sortByDesc(fn (DailyMoneyRegister $register) => $register->business_date->toDateString())->first();
        $calculation = collect($rows)->firstWhere('business_date', $today) ?? end($rows) ?: null;

        return [
            'period' => compact('from', 'to'),
            'outlet_id' => $outletId,
            'summary' => [
                'collections' => Money::fromMinor($periodCollections),
                'expenses' => Money::fromMinor($periodExpenses),
                'other_income' => Money::fromMinor($periodIncome),
                'actual_online' => $latest?->actual_online !== null ? Money::fromMinor(Money::toMinor($latest->actual_online)) : null,
                'actual_cash' => $latest?->actual_cash !== null ? Money::fromMinor(Money::toMinor($latest->actual_cash)) : null,
            ],
            'calculation' => $calculation,
            'rows' => array_reverse($rows),
        ];
    }

    /** @return Collection<int, Payment> */
    private function payments(Hotel $hotel, int $outletId, string $from, string $to)
    {
        $timezone = $hotel->timezone ?: config('app.timezone');
        $cutoff = substr((string) ($hotel->business_day_starts_at ?: '05:00:00'), 0, 8);
        $start = CarbonImmutable::parse($from.' '.$cutoff, $timezone)->utc();
        $end = CarbonImmutable::parse($to.' '.$cutoff, $timezone)->addDay()->utc();

        return Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.hotel_id', $hotel->id)
            ->where('invoices.outlet_id', $outletId)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereIn('payments.type', [PaymentType::Payment->value, PaymentType::Refund->value])
            ->where('payments.paid_at', '>=', $start)
            ->where('payments.paid_at', '<', $end)
            ->get(['payments.id', 'payments.amount', 'payments.paid_at']);
    }
}
