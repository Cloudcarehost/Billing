<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\CustomerComplaint;
use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomerComplaintReportService
{
    public function overview(Hotel $hotel, array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $outletId = $filters['outlet_id'] ?? null;
        $complaints = $this->query($hotel, $from, $to, $outletId);
        $totals = (clone $complaints)->selectRaw("COUNT(*) total, SUM(CASE WHEN status IN ('open', 'under_review') THEN 1 ELSE 0 END) open_count, SUM(CASE WHEN outcome = 'restaurant_issue' THEN 1 ELSE 0 END) restaurant_issue_count, SUM(CASE WHEN outcome = 'shared_responsibility' THEN 1 ELSE 0 END) shared_count, SUM(CASE WHEN outcome = 'customer_misunderstanding' THEN 1 ELSE 0 END) misunderstanding_count, SUM(CASE WHEN outcome = 'inconclusive' THEN 1 ELSE 0 END) inconclusive_count")->first();
        $billedUnits = $this->billedUnitsQuery($hotel, $from, $to, $outletId)->sum('invoice_items.quantity');
        $reviewed = (int) $totals->restaurant_issue_count + (int) $totals->shared_count + (int) $totals->misunderstanding_count;
        $supported = (int) $totals->restaurant_issue_count + (int) $totals->shared_count;

        $reasonBreakdown = (clone $complaints)->selectRaw('reason_code as label, COUNT(*) as count')->groupBy('reason_code')->orderByDesc('count')->get();
        $responsibilityBreakdown = (clone $complaints)->selectRaw("COALESCE(responsibility_area, 'pending') as label, COUNT(*) as count")->groupBy('responsibility_area')->orderByDesc('count')->get();
        $outcomeBreakdown = (clone $complaints)->selectRaw("COALESCE(outcome, 'pending') as label, COUNT(*) as count")->groupBy('outcome')->orderByDesc('count')->get();
        $staffBreakdown = DB::table('customer_complaint_users')
            ->join('customer_complaints', 'customer_complaints.id', '=', 'customer_complaint_users.complaint_id')
            ->where('customer_complaints.hotel_id', $hotel->id)
            ->whereBetween('customer_complaints.business_date', [$from, $to])
            ->where('customer_complaint_users.attribution', 'responsible')
            ->when($outletId, fn ($query) => $query->where('customer_complaints.outlet_id', $outletId))
            ->selectRaw('customer_complaint_users.user_name_snapshot as name, COUNT(*) as count')
            ->groupBy('customer_complaint_users.user_name_snapshot')
            ->orderByDesc('count')->limit(10)->get();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'metrics' => [
                'total' => (int) $totals->total,
                'open' => (int) $totals->open_count,
                'restaurant_issues' => (int) $totals->restaurant_issue_count,
                'shared_responsibility' => (int) $totals->shared_count,
                'customer_misunderstandings' => (int) $totals->misunderstanding_count,
                'inconclusive' => (int) $totals->inconclusive_count,
                'billed_item_units' => (float) $billedUnits,
                'complaints_per_100_billed_items' => (float) $billedUnits > 0 ? round((int) $totals->total / (float) $billedUnits * 100, 2) : null,
            ],
            'customer_trust' => [
                'reviewed_complaints' => $reviewed,
                'report_accuracy_percent' => $reviewed > 0 ? round($supported / $reviewed * 100, 1) : null,
                'sample_sufficient' => $reviewed >= 10,
            ],
            'trend' => $this->trend($hotel, $from, $to, $outletId),
            'reasons' => $reasonBreakdown,
            'responsibility_areas' => $responsibilityBreakdown,
            'outcomes' => $outcomeBreakdown,
            'responsible_staff' => $staffBreakdown,
        ];
    }

    private function query(Hotel $hotel, string $from, string $to, ?int $outletId): Builder
    {
        return CustomerComplaint::query()
            ->where('hotel_id', $hotel->id)
            ->whereBetween('business_date', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId));
    }

    private function billedUnitsQuery(Hotel $hotel, string $from, string $to, ?int $outletId)
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.hotel_id', $hotel->id)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereBetween('invoices.business_date', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('invoices.outlet_id', $outletId));
    }

    private function trend(Hotel $hotel, string $from, string $to, ?int $outletId): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $weekly = $start->diffInDays($end) > 45;
        $bucket = fn (string $date) => $weekly
            ? CarbonImmutable::parse($date)->startOfWeek()->toDateString()
            : CarbonImmutable::parse($date)->toDateString();

        $complaintCounts = $this->query($hotel, $from, $to, $outletId)
            ->selectRaw('business_date, COUNT(*) as count')->groupBy('business_date')->get()
            ->groupBy(fn ($row) => $bucket((string) $row->business_date))
            ->map(fn ($rows) => (int) $rows->sum('count'));
        $itemUnits = $this->billedUnitsQuery($hotel, $from, $to, $outletId)
            ->selectRaw('invoices.business_date, SUM(invoice_items.quantity) as units')
            ->groupBy('invoices.business_date')->get()
            ->groupBy(fn ($row) => $bucket((string) $row->business_date))
            ->map(fn ($rows) => (float) $rows->sum('units'));

        $points = [];
        $cursor = $weekly ? $start->startOfWeek() : $start;
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $complaintCount = (int) ($complaintCounts[$key] ?? 0);
            $units = (float) ($itemUnits[$key] ?? 0);
            $points[] = [
                'date' => $key,
                'label' => $weekly ? $cursor->format('d M') : $cursor->format('d M'),
                'complaints' => $complaintCount,
                'billed_item_units' => $units,
                'rate_per_100_items' => $units > 0 ? round($complaintCount / $units * 100, 2) : null,
            ];
            $cursor = $weekly ? $cursor->addWeek() : $cursor->addDay();
        }

        return $points;
    }
}
