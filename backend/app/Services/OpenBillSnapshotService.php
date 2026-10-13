<?php

namespace App\Services;

use App\Jobs\SyncOpenBillsToGoogleSheet;
use App\Models\DiningSession;
use App\Models\Hotel;
use App\Support\Money;
use App\Support\Quantity;
use Throwable;

class OpenBillSnapshotService
{
    public function __construct(private readonly GoogleSheetsWriter $sheets) {}

    public function configured(): bool
    {
        return (bool) config('google_sheets.enabled') && filled(config('google_sheets.spreadsheet_id'));
    }

    /** Queue a full rewrite after the business write commits. Failures never bubble into billing. */
    public static function queue(int $hotelId): void
    {
        try {
            $service = app(self::class);
            if (! $service->configured()) {
                return;
            }
            SyncOpenBillsToGoogleSheet::dispatch($hotelId)->afterCommit(! app()->runningUnitTests());
        } catch (Throwable) {
            // Emergency sheet copy must not roll back or delay orders, kitchen, or billing.
        }
    }

    public function push(int $hotelId): void
    {
        if (! $this->configured()) {
            return;
        }

        $this->sheets->rewriteWorkbook((string) config('google_sheets.spreadsheet_id'), $this->snapshot($hotelId));
    }

    /**
     * @return array<string, list<list<string>>>
     */
    public function snapshot(int $hotelId): array
    {
        $hotel = Hotel::query()->find($hotelId);
        $tz = filled($hotel?->timezone) ? (string) $hotel->timezone : 'Asia/Kolkata';
        $banner = 'Last synced: '.now($tz)->format('d M Y H:i T').'. Website down? Use these totals. Orders after this time are missing.';
        $openTab = (string) config('google_sheets.open_tables_tab', 'Open tables');
        $linesTab = (string) config('google_sheets.lines_tab', 'Lines');

        $tableRows = [
            [$banner],
            [''],
            ['Table', 'Type', 'Guests', 'Status', 'Subtotal', 'Discount', 'Tax', 'Total'],
        ];
        $lineRows = [
            [$banner],
            [''],
            ['Table', 'Item', 'Qty', 'Rate', 'Line total'],
        ];

        $sessions = DiningSession::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', ['occupied', 'pending_bill'])
            ->with(['diningTable:id,name,service_type', 'joinedTables:id,name', 'orders.items'])
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $table = $session->displayName();
            $tableRows[] = [
                $table,
                $session->diningTable?->isParcel() ? 'Parcel' : 'Dine-in',
                (string) $session->guest_count,
                (string) $session->status,
                (string) $session->subtotal,
                (string) $session->discount_amount,
                (string) $session->tax_amount,
                (string) $session->total_amount,
            ];
            foreach ($this->groupedLines($session) as $line) {
                $lineRows[] = [$table, $line['name'], $line['qty'], $line['rate'], $line['total']];
            }
        }

        return [$openTab => $tableRows, $linesTab => $lineRows];
    }

    /**
     * @return list<array{name: string, qty: string, rate: string, total: string}>
     */
    private function groupedLines(DiningSession $session): array
    {
        $groups = [];
        foreach ($session->orders as $order) {
            foreach ($order->items as $item) {
                if ($item->status === 'cancelled') {
                    continue;
                }
                $rate = (string) $item->unit_price;
                $key = $item->item_name.'|'.$rate;
                if (! isset($groups[$key])) {
                    $groups[$key] = ['name' => (string) $item->item_name, 'qty' => 0, 'rate' => $rate, 'total' => 0];
                }
                $groups[$key]['qty'] += Quantity::toMilli($item->quantity);
                $groups[$key]['total'] += Money::toMinor($item->line_total);
            }
        }

        return array_values(array_map(fn (array $group) => [
            'name' => $group['name'],
            'qty' => $this->qtyLabel($group['qty']),
            'rate' => $group['rate'],
            'total' => Money::fromMinor($group['total']),
        ], $groups));
    }

    private function qtyLabel(int $milli): string
    {
        if ($milli % Quantity::SCALE === 0) {
            return (string) intdiv($milli, Quantity::SCALE);
        }

        return rtrim(rtrim(Quantity::fromMilli($milli), '0'), '.');
    }
}
