<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\StandingCost;
use App\Support\Money;
use Carbon\CarbonImmutable;

class FinanceService
{
    /** @return array<string, mixed> */
    public function summary(Hotel $hotel, string $from, string $to): array
    {
        $billed = Money::fromMinor(Money::toMinor(Invoice::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereBetween('business_date', [$from, $to.' 23:59:59'])
            ->sum('total_amount')));

        $entries = LedgerEntry::query()
            ->where('hotel_id', $hotel->id)
            ->whereBetween('occurred_on', [$from, $to])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        $otherIncomeMinor = 0;
        $extraExpenseMinor = 0;
        foreach ($entries as $entry) {
            $minor = Money::toMinor($entry->amount);
            if ($entry->type === 'income') {
                $otherIncomeMinor += $minor;
            } else {
                $extraExpenseMinor += $minor;
            }
        }

        $standing = $this->standingLines($hotel, $from, $to);
        $standingMinor = array_sum(array_map(fn (array $line) => Money::toMinor($line['amount']), $standing));
        $otherIncome = Money::fromMinor($otherIncomeMinor);
        $extraExpenses = Money::fromMinor($extraExpenseMinor);
        $expenses = Money::fromMinor($standingMinor + $extraExpenseMinor);
        $remaining = Money::fromMinor(Money::toMinor($billed) + $otherIncomeMinor - $standingMinor - $extraExpenseMinor);

        return [
            'period' => compact('from', 'to'),
            'billed' => $billed,
            'other_income' => $otherIncome,
            'standing_total' => Money::fromMinor($standingMinor),
            'extra_expenses' => $extraExpenses,
            'expenses' => $expenses,
            'remaining' => $remaining,
            'standing' => $standing,
            'entries' => $this->movementEntries($entries, $standing, $from),
        ];
    }

    /**
     * @return list<array{key: string, source: string, name: string, pay_cycle: string, rate: string, units: int, amount: string, detail: string, due_on: ?string, due_dates: list<string>}>
     */
    public function standingLines(Hotel $hotel, string $from, string $to): array
    {
        $lines = [];
        $members = $hotel->users()
            ->wherePivot('is_active', true)
            ->whereNotNull('hotel_user.salary_amount')
            ->whereNotNull('hotel_user.pay_cycle')
            ->orderBy('users.name')
            ->get();

        foreach ($members as $user) {
            $rate = (string) $user->pivot->salary_amount;
            if (Money::toMinor($rate) <= 0) {
                continue;
            }
            $cycle = (string) $user->pivot->pay_cycle;
            $period = $this->periodAmount($cycle, $rate, $from, $to, $user->pivot->joined_at ? (string) $user->pivot->joined_at : null);
            if ($period['units'] < 1) {
                continue;
            }
            $lines[] = [
                'key' => 'staff-'.$user->id,
                'source' => 'staff',
                'name' => $user->name.' salary',
                'pay_cycle' => $cycle,
                'rate' => Money::fromMinor(Money::toMinor($rate)),
                'units' => $period['units'],
                'amount' => $period['amount'],
                'detail' => $period['detail'],
                'due_on' => null,
                'due_dates' => $period['due_dates'],
            ];
        }

        $costs = StandingCost::query()
            ->where('hotel_id', $hotel->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($costs as $cost) {
            $dueOn = $cost->due_on?->toDateString();
            $period = $this->periodAmount($cost->pay_cycle, (string) $cost->amount, $from, $to, null, $dueOn);
            if ($period['units'] < 1) {
                continue;
            }
            $lines[] = [
                'key' => 'bill-'.$cost->id,
                'source' => 'standing',
                'name' => $cost->name,
                'pay_cycle' => $cost->pay_cycle,
                'rate' => Money::fromMinor(Money::toMinor($cost->amount)),
                'units' => $period['units'],
                'amount' => $period['amount'],
                'detail' => $period['detail'],
                'due_on' => $dueOn,
                'due_dates' => $period['due_dates'],
            ];
        }

        return $lines;
    }

    /**
     * @return array{units: int, amount: string, detail: string, due_dates: list<string>}
     */
    public function periodAmount(string $cycle, string $amount, string $from, string $to, ?string $joinedAt = null, ?string $dueOn = null): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($joinedAt) {
            $joined = CarbonImmutable::parse($joinedAt)->startOfDay();
            if ($joined->greaterThan($end)) {
                return ['units' => 0, 'amount' => Money::fromMinor(0), 'detail' => 'Not yet joined', 'due_dates' => []];
            }
            if ($joined->greaterThan($start)) {
                $start = $joined;
            }
        }
        if ($start->greaterThan($end)) {
            return ['units' => 0, 'amount' => Money::fromMinor(0), 'detail' => 'Outside period', 'due_dates' => []];
        }

        $rateMinor = Money::toMinor($amount);
        if ($dueOn) {
            $dates = $this->dueDatesInRange($cycle, CarbonImmutable::parse($dueOn)->startOfDay(), $start, $end);
            $units = count($dates);

            return [
                'units' => $units,
                'amount' => Money::fromMinor($rateMinor * $units),
                'detail' => $this->dueDetail($cycle, $dueOn, $units),
                'due_dates' => array_map(fn (CarbonImmutable $date) => $date->toDateString(), $dates),
            ];
        }

        $days = $start->diffInDays($end) + 1;

        if ($cycle === 'daily') {
            return [
                'units' => $days,
                'amount' => Money::fromMinor($rateMinor * $days),
                'detail' => $days.' day(s)',
                'due_dates' => [],
            ];
        }

        if ($cycle === 'weekly') {
            $units = (int) ceil($days / 7);

            return [
                'units' => $units,
                'amount' => Money::fromMinor($rateMinor * $units),
                'detail' => $units.' week(s)',
                'due_dates' => [],
            ];
        }

        $units = 0;
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();
        while ($cursor->lessThanOrEqualTo($last)) {
            $units++;
            $cursor = $cursor->addMonth();
        }

        return [
            'units' => $units,
            'amount' => Money::fromMinor($rateMinor * $units),
            'detail' => $units.' month(s)',
            'due_dates' => [],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LedgerEntry>  $entries
     * @param  list<array{key: string, source: string, name: string, pay_cycle: string, rate: string, units: int, amount: string, detail: string, due_on: ?string, due_dates: list<string>}>  $standing
     * @return list<array<string, mixed>>
     */
    private function movementEntries($entries, array $standing, string $from): array
    {
        $rows = $entries->load('creator:id,name')->map(function (LedgerEntry $entry) {
            $occurred = $entry->occurred_on;

            return [
                'id' => $entry->id,
                'type' => $entry->type,
                'category' => $entry->category,
                'amount' => Money::fromMinor(Money::toMinor($entry->amount)),
                'comment' => $entry->comment,
                'occurred_on' => $occurred instanceof CarbonImmutable || $occurred instanceof \DateTimeInterface
                    ? CarbonImmutable::parse($occurred)->toDateString()
                    : (string) $occurred,
                'creator' => $entry->creator ? ['id' => $entry->creator->id, 'name' => $entry->creator->name] : null,
                'source' => 'ledger',
                'editable' => true,
            ];
        })->all();

        foreach ($standing as $line) {
            $dates = $line['due_dates'];
            if ($dates !== []) {
                foreach ($dates as $date) {
                    $rows[] = [
                        'id' => $line['key'].'-'.$date,
                        'type' => 'expense',
                        'category' => $line['name'],
                        'amount' => $line['rate'],
                        'comment' => $line['detail'],
                        'occurred_on' => $date,
                        'creator' => null,
                        'source' => $line['source'],
                        'editable' => false,
                    ];
                }

                continue;
            }

            $rows[] = [
                'id' => $line['key'],
                'type' => 'expense',
                'category' => $line['name'],
                'amount' => $line['amount'],
                'comment' => $line['detail'],
                'occurred_on' => $from,
                'creator' => null,
                'source' => $line['source'],
                'editable' => false,
            ];
        }

        usort($rows, function (array $left, array $right) {
            $date = strcmp((string) $right['occurred_on'], (string) $left['occurred_on']);
            if ($date !== 0) {
                return $date;
            }
            $leftId = is_numeric($left['id']) ? (int) $left['id'] : 0;
            $rightId = is_numeric($right['id']) ? (int) $right['id'] : 0;

            return $rightId <=> $leftId;
        });

        return array_values($rows);
    }

    /** @return list<CarbonImmutable> */
    private function dueDatesInRange(string $cycle, CarbonImmutable $anchor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = [];

        if ($cycle === 'daily') {
            $cursor = $start;
            while ($cursor->lessThanOrEqualTo($end)) {
                $dates[] = $cursor;
                $cursor = $cursor->addDay();
            }

            return $dates;
        }

        if ($cycle === 'weekly') {
            $cursor = $start;
            while ($cursor->dayOfWeek !== $anchor->dayOfWeek && $cursor->lessThanOrEqualTo($end)) {
                $cursor = $cursor->addDay();
            }
            while ($cursor->lessThanOrEqualTo($end)) {
                $dates[] = $cursor;
                $cursor = $cursor->addWeek();
            }

            return $dates;
        }

        $day = min($anchor->day, 31);
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();
        while ($cursor->lessThanOrEqualTo($last)) {
            $due = $cursor->setDay(min($day, $cursor->daysInMonth));
            if ($due->betweenIncluded($start, $end)) {
                $dates[] = $due;
            }
            $cursor = $cursor->addMonth();
        }

        return $dates;
    }

    private function dueDetail(string $cycle, string $dueOn, int $units): string
    {
        $anchor = CarbonImmutable::parse($dueOn);

        if ($cycle === 'daily') {
            return $units.' day(s)';
        }

        if ($cycle === 'weekly') {
            return $units === 1
                ? 'Pay on '.$anchor->format('l')
                : $units.' '.$anchor->format('l').'(s)';
        }

        return $units === 1
            ? 'Pay on the '.$anchor->day.' each month'
            : $units.' month(s) · the '.$anchor->day;
    }
}
