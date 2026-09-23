<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReportExport;
use App\Models\StockMovement;
use App\Services\ReportService;
use App\Support\HotelDate;
use Dompdf\Dompdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportService $reports): void
    {
        $export = ReportExport::query()->with('requester')->findOrFail($this->exportId);
        $export->update(['status' => 'processing']);
        try {
            $today = HotelDate::businessDate($export->hotel);
        $filters = ['from' => $today, 'to' => $today] + ($export->filters ?? []);
            $rows = match ($export->type) {
                'invoice_register' => $reports->invoiceRegister($export->hotel, $filters)->map(fn ($invoice) => [$invoice->invoice_number, $invoice->business_date->toDateString(), $invoice->outlet?->name, $invoice->status, $invoice->payment_status, $invoice->subtotal, $invoice->tax_amount, $invoice->total_amount, $invoice->paid_amount, $invoice->balance_amount]),
                'payment_reconciliation' => Payment::query()->whereHas('invoice', fn ($query) => $query->where('hotel_id', $export->hotel_id))->whereBetween('paid_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])->get()->map(fn ($payment) => [$payment->invoice_id, $payment->type, $payment->method, $payment->amount, $payment->reference_number, $payment->paid_at]),
                'inventory_movements' => StockMovement::query()->whereHas('stock.outlet', fn ($query) => $query->where('hotel_id', $export->hotel_id))->with('stock.product', 'stock.outlet')->latest('occurred_at')->get()->map(fn ($movement) => [$movement->occurred_at, $movement->stock?->outlet?->name, $movement->stock?->product?->name, $movement->type, $movement->quantity_delta, $movement->balance_after, $movement->unit_cost, $movement->reason]),
                default => Invoice::query()->where('hotel_id', $export->hotel_id)->whereBetween('business_date', [$filters['from'], $filters['to']])->where('status', 'issued')->get()->map(fn ($invoice) => [$invoice->business_date, $invoice->invoice_number, $invoice->tax_amount, $invoice->cgst_amount, $invoice->sgst_amount, $invoice->igst_amount]),
            };
            $basePath = 'exports/hotel-'.$export->hotel_id.'/'.str($export->type.'-'.$export->id)->slug();
            if ($export->format === 'pdf') {
                $body = collect($rows)->map(fn ($row) => '<tr>'.collect($row)->map(fn ($value) => '<td>'.e((string) $value).'</td>')->implode('').'</tr>')->implode('');
                $pdf = new Dompdf;
                $pdf->loadHtml('<h1>'.e(str($export->type)->headline()).'</h1><table border="1" cellspacing="0" cellpadding="5">'.$body.'</table>');
                $pdf->setPaper('a4', 'landscape');
                $pdf->render();
                $path = $basePath.'.pdf';
                Storage::disk('local')->put($path, $pdf->output());
            } else {
                $stream = fopen('php://temp', 'r+');
                foreach ($rows as $row) {
                    fputcsv($stream, $row);
                } rewind($stream);
                $path = $basePath.'.csv';
                Storage::disk('local')->put($path, stream_get_contents($stream));
                fclose($stream);
            } $export->update(['status' => 'completed', 'disk' => 'local', 'path' => $path]);
        } catch (\Throwable $exception) {
            $export->update(['status' => 'failed', 'error_message' => app()->isProduction() ? 'Export failed. Please try again.' : $exception->getMessage()]);
            throw $exception;
        }
    }
}
