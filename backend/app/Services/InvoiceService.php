<?php

namespace App\Services;

use App\Events\RestaurantUpdated;
use App\Models\DiningSession;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use App\Support\RestaurantRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function void(Invoice $invoice, string $reason, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $user) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $session = $invoice->dining_session_id
                ? DiningSession::query()->lockForUpdate()->find($invoice->dining_session_id)
                : null;
            if ($invoice->paid_amount > 0) {
                throw ValidationException::withMessages(['invoice' => ['Refund payments before voiding an invoice.']]);
            } if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => ['Only issued invoices can be voided.']]);
            } $invoice->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
            if ($session && $session->status !== 'closed') {
                $session->update(['status' => 'closed', 'closed_at' => now()]);
                RestaurantUpdated::dispatch('table_closed', $invoice->hotel_id, $invoice->outlet_id, $session->dining_table_id, $session->id, null, null, $session->waiter_id, null, RestaurantRealtime::payload($session->load('diningTable', 'waiter'), ['invoice_id' => $invoice->id, 'voided' => true]));
            }
            ReportService::invalidateDashboard($invoice->hotel_id);

            return $invoice->fresh()->load('diningSession.diningTable');
        });
    }

    public function reopen(Invoice $invoice, string $reason, User $user): DiningSession
    {
        return DB::transaction(function () use ($invoice, $reason, $user) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $session = $invoice->dining_session_id
                ? DiningSession::query()->lockForUpdate()->find($invoice->dining_session_id)
                : null;
            if ($session === null) {
                throw ValidationException::withMessages(['invoice' => ['This bill is not linked to an open table or parcel.']]);
            }
            if (Money::toMinor($invoice->paid_amount) > 0) {
                throw ValidationException::withMessages(['invoice' => ['Refund payments before correcting this bill.']]);
            }
            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => ['Only an unpaid issued bill can be corrected.']]);
            }
            $invoice->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
            $session->update(['status' => 'occupied', 'closed_at' => null]);
            ReportService::invalidateDashboard($invoice->hotel_id);
            RestaurantUpdated::dispatch('invoice_reopened', $invoice->hotel_id, $invoice->outlet_id, $session->dining_table_id, $session->id, null, null, $session->waiter_id, null, RestaurantRealtime::payload($session->load('diningTable', 'waiter', 'orders.items'), [
                'invoice_id' => $invoice->id,
                'voided' => true,
                'session_status' => 'occupied',
            ]));

            return $session->fresh()->load('diningTable', 'waiter', 'orders.items', 'invoice');
        });
    }

    public function reprint(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $invoice->increment('reprint_count');
            $invoice->update(['last_reprinted_at' => now()]);

            return $invoice->fresh();
        });
    }
}
