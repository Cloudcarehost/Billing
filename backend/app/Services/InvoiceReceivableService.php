<?php

namespace App\Services;

use App\Events\RestaurantUpdated;
use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\Invoice;
use App\Models\InvoiceReceivable;
use App\Models\User;
use App\Support\RestaurantRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceReceivableService
{
    public function keepPending(Invoice $invoice, User $user, array $data, string $type, AuditService $audit): Invoice
    {
        return DB::transaction(function () use ($invoice, $user, $data, $type, $audit) {
            $invoice = Invoice::query()->with('diningSession.joinedTables')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued' || $invoice->payment_status === 'paid' || (float) $invoice->balance_amount <= 0) {
                throw ValidationException::withMessages(['invoice' => ['Only an issued invoice with an outstanding balance can be kept pending.']]);
            }
            if ($type === 'owner_account' && ! $invoice->charged_to_user_id) {
                throw ValidationException::withMessages(['invoice' => ['Select an owner before closing this bill to an owner account.']]);
            }
            if ($invoice->receivable()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => ['This invoice is already in outstanding collections.']]);
            }

            $invoice->update([
                'customer_name' => $this->blank($data['customer_name'] ?? null) ?? $invoice->customer_name,
                'customer_phone' => $this->blank($data['customer_phone'] ?? null) ?? $invoice->customer_phone,
                'customer_email' => $this->blank($data['customer_email'] ?? null) ?? $invoice->customer_email,
                'customer_gstin' => $this->blank($data['customer_gstin'] ?? null) ?? $invoice->customer_gstin,
            ]);
            $receivable = InvoiceReceivable::query()->create([
                'hotel_id' => $invoice->hotel_id,
                'outlet_id' => $invoice->outlet_id,
                'invoice_id' => $invoice->id,
                'assigned_to_user_id' => $data['assigned_to_user_id'] ?? $invoice->charged_to_user_id,
                'opened_by' => $user->id,
                'type' => $type,
                'status' => 'open',
                'note' => $this->blank($data['note'] ?? null),
                'opened_at' => now(),
            ]);

            $session = $invoice->diningSession;
            if ($session && $session->status !== 'closed') {
                $this->releaseTables($session, $invoice);
            }

            $audit->record($receivable, 'billing.receivable_opened', $user, $invoice->hotel_id, $invoice->outlet_id, [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $type,
                'balance_amount' => $invoice->balance_amount,
                'assigned_to_user_id' => $receivable->assigned_to_user_id,
            ]);
            ReportService::invalidateDashboard($invoice->hotel_id);
            OpenBillSnapshotService::queue($invoice->hotel_id);

            return $invoice->fresh()->load($this->relations());
        });
    }

    public function settleIfPaid(Invoice $invoice, User $user): void
    {
        if ($invoice->payment_status !== 'paid') {
            return;
        }
        $receivable = InvoiceReceivable::query()->where('invoice_id', $invoice->id)->where('status', 'open')->lockForUpdate()->first();
        if ($receivable) {
            $receivable->update(['status' => 'settled', 'settled_by' => $user->id, 'settled_at' => now()]);
        }
    }

    public function void(Invoice $invoice): void
    {
        $updated = InvoiceReceivable::query()->where('invoice_id', $invoice->id)->where('status', 'open')->update([
            'status' => 'voided',
            'settled_at' => now(),
        ]);
        if ($updated) {
            RestaurantUpdated::dispatch('receivable_updated', $invoice->hotel_id, $invoice->outlet_id, null, $invoice->dining_session_id, null, null, null, null, [
                'invoice_id' => $invoice->id,
                'status' => 'voided',
            ]);
        }
    }

    /** @return list<string> */
    public function relations(): array
    {
        return [
            'outlet:id,name,code', 'customer:id,name,phone,email,gstin', 'creator:id,name', 'chargedTo:id,name',
            'items.product:id,name,sku', 'payments.receiver:id,name', 'diningSession.diningTable',
            'receivable.assignee:id,name', 'receivable.opener:id,name', 'receivable.settler:id,name',
            'replaces:id,invoice_number,status', 'replacement:id,invoice_number,status,replaces_invoice_id',
        ];
    }

    private function releaseTables(DiningSession $session, Invoice $invoice): void
    {
        $session = DiningSession::query()->with('diningTable', 'waiter', 'joinedTables:id,name')->lockForUpdate()->findOrFail($session->id);
        $ids = $session->memberTableIds();
        DiningTable::query()->whereIn('id', $ids)->whereNotNull('waiter_called_at')->update(['waiter_called_at' => null]);
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $payload = RestaurantRealtime::payload($session->fresh(['diningTable', 'waiter', 'joinedTables:id,name']), [
            'invoice_id' => $invoice->id,
            'receivable_opened' => true,
            'joined_table_ids' => $ids,
        ]);
        foreach ($ids as $tableId) {
            RestaurantUpdated::dispatch('table_closed', $invoice->hotel_id, $invoice->outlet_id, $tableId, $session->id, null, null, $session->waiter_id, null, $payload);
        }
        $session->joinedTables()->detach();
    }

    private function blank(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
