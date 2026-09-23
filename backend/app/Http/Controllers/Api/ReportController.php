<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GenerateReportExport;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Outlet;
use App\Models\ReportExport;
use App\Services\ReportService;
use App\Support\HotelDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends ApiController
{
    public function dashboard(Request $request, ReportService $reports): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success($reports->dashboard($hotel, $this->filters($request, $hotel)));
    }

    public function invoices(Request $request, ReportService $reports): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success($reports->invoiceRegister($hotel, $this->filters($request, $hotel)));
    }

    public function payments(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $filters = $this->filters($request, $hotel);

        return $this->success(Invoice::query()->where('invoices.hotel_id', $hotel->id)->where('invoices.status', 'issued')->whereBetween('invoices.business_date', [$filters['from'], $filters['to'].' 23:59:59'])->when($filters['outlet_id'] ?? null, fn ($query, $id) => $query->where('invoices.outlet_id', $id))->join('payments', 'payments.invoice_id', '=', 'invoices.id')->selectRaw('payments.method, payments.type, SUM(payments.amount) as amount, COUNT(payments.id) as count')->groupBy('payments.method', 'payments.type')->get());
    }

    public function export(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['type' => ['required', 'in:invoice_register,payment_reconciliation,inventory_movements,tax_summary'], 'format' => ['required', 'in:csv,pdf'], 'filters' => ['nullable', 'array']]);
        $export = ReportExport::query()->create(['hotel_id' => $hotel->id, 'requested_by' => $request->user()->id, 'type' => $data['type'], 'format' => $data['format'], 'filters' => $data['filters'] ?? [], 'status' => 'queued', 'expires_at' => now()->addDays(7)]);
        GenerateReportExport::dispatch($export->id);

        return $this->success($export, 'Export queued.', 202);
    }

    public function exports(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->paginated(ReportExport::query()->where('hotel_id', $hotel->id)->latest()->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function download(Request $request, int $export)
    {
        $hotel = $request->attributes->get('currentHotel');
        $export = ReportExport::query()->where('hotel_id', $hotel->id)->where('status', 'completed')->findOrFail($export);
        abort_unless($export->path && $export->disk && Storage::disk($export->disk)->exists($export->path), 404);

        return Storage::disk($export->disk)->download($export->path);
    }

    private function filters(Request $request, Hotel $hotel): array
    {
        $data = $request->validate(['period' => ['nullable', 'in:today,week,month,year'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'outlet_id' => ['nullable', 'integer']]);
        $today = HotelDate::businessDate($hotel);
        if (empty($data['from']) && ! empty($data['period'])) {
            [$data['from'], $data['to']] = HotelDate::rangeForPeriod($hotel, $data['period']);
        }
        $data['from'] ??= $today;
        $data['to'] ??= $data['from'];
        if (! empty($data['outlet_id'])) {
            Outlet::query()->where('hotel_id', $request->attributes->get('currentHotel')->id)->whereIn('id', $request->attributes->get('accessibleOutletIds', []))->findOrFail($data['outlet_id']);
        } elseif ($request->attributes->get('currentOutletId')) {
            $data['outlet_id'] = $request->attributes->get('currentOutletId');
        }

        return $data;
    }
}
