<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Events\RestaurantUpdated;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function refund(Invoice $invoice, Payment $payment, float $amount, string $reason, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $payment, $amount, $reason, $user) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $payment = Payment::query()->where('invoice_id', $invoice->id)->lockForUpdate()->findOrFail($payment->id);
            $refundAmountMinor = Money::toMinor($amount);
            $alreadyRefundedMinor = Payment::query()
                ->where('refunded_payment_id', $payment->id)
                ->where('type', PaymentType::Refund->value)
                ->lockForUpdate()
                ->get()
                ->sum(fn (Payment $refund) => abs(Money::toMinor($refund->amount)));
            $refundableMinor = Money::toMinor($payment->amount) - $alreadyRefundedMinor;
            if ($payment->type !== PaymentType::Payment->value || $refundAmountMinor <= 0 || $refundAmountMinor > $refundableMinor) {
                throw ValidationException::withMessages(['payment' => ['This payment cannot be refunded for that amount.']]);
            }
            $invoice->payments()->create([
                'received_by' => $user->id,
                'type' => PaymentType::Refund->value,
                'method' => $payment->method,
                'amount' => Money::fromMinor(-$refundAmountMinor),
                'refunded_payment_id' => $payment->id,
                'notes' => $reason,
                'paid_at' => now(),
            ]);
            $paidMinor = $invoice->payments()->get()->sum(fn (Payment $entry) => Money::toMinor($entry->amount));
            $totalMinor = Money::toMinor($invoice->total_amount);
            $balanceMinor = max(0, $totalMinor - $paidMinor);
            $status = $paidMinor <= 0 ? PaymentStatus::Unpaid->value : ($balanceMinor === 0 ? PaymentStatus::Paid->value : PaymentStatus::Partial->value);
            $invoice->update(['paid_amount' => Money::fromMinor($paidMinor), 'balance_amount' => Money::fromMinor($balanceMinor), 'payment_status' => $status]);
            if ($balanceMinor > 0 && $invoice->receivable()->exists()) {
                $invoice->receivable()->update(['status' => 'open', 'settled_by' => null, 'settled_at' => null]);
            }
            ReportService::invalidateDashboard($invoice->hotel_id);
            if ($invoice->receivable()->exists()) {
                RestaurantUpdated::dispatch('receivable_updated', $invoice->hotel_id, $invoice->outlet_id, null, $invoice->dining_session_id, null, null, null, null, [
                    'invoice_id' => $invoice->id,
                    'payment_status' => $invoice->payment_status,
                    'paid_amount' => $invoice->paid_amount,
                    'balance_amount' => $invoice->balance_amount,
                ]);
            }

            return $invoice->fresh()->load('payments');
        });
    }
}
