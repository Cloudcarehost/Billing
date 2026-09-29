<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Customer;
use App\Models\DiningSession;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DiningBillingService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends ApiController
{
    public function create(Request $request, int $session, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $session = DiningSession::query()->where('hotel_id', $hotel->id)->findOrFail($session);

        $guest = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_gstin' => ['nullable', 'string', 'max:20'],
            'charged_to_user_id' => ['nullable', 'integer'],
        ]);
        if (! empty($guest['customer_id'])) {
            Customer::query()->where('hotel_id', $hotel->id)->findOrFail($guest['customer_id']);
        }
        if (! empty($guest['charged_to_user_id'])) {
            $owner = $this->ownerAccount($request, (int) $guest['charged_to_user_id']);
            $guest['customer_name'] = filled($guest['customer_name'] ?? null) ? $guest['customer_name'] : $owner->name;
        }

        return $this->success($service->createInvoice($session, $request->user(), $hotel, $guest), 'Invoice created.', 201);
    }

    public function index(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? ($from ?: null);
        $search = trim((string) ($data['q'] ?? ''));

        $invoices = Invoice::query()
            ->where('hotel_id', $hotel->id)
            ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id))
            ->when($from, fn ($query) => $query->whereDate('business_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('business_date', '<=', $to))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('invoice_number', 'like', $term)
                        ->orWhere('customer_name', 'like', $term)
                        ->orWhere('customer_phone', 'like', $term)
                        ->orWhereHas('diningSession.diningTable', function ($table) use ($term) {
                            $table->where('name', 'like', $term)->orWhere('code', 'like', $term);
                        });
                });
            })
            ->with([
                'outlet:id,name,code',
                'customer:id,name,phone',
                'chargedTo:id,name',
                'creator:id,name',
                'diningSession.diningTable:id,name,code,service_type',
                'payments:id,invoice_id,method,type,amount',
            ])
            ->orderByDesc('billed_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($data['per_page'] ?? 20), 50));

        $invoices->getCollection()->transform(function (Invoice $invoice) {
            $methods = $invoice->payments
                ->where('type', '!=', PaymentType::Refund->value)
                ->pluck('method')
                ->unique()
                ->values()
                ->all();
            $invoice->setAttribute('table_name', $invoice->diningSession?->diningTable?->name);
            $invoice->setAttribute('service_type', $invoice->diningSession?->diningTable?->service_type ?? 'dine_in');
            $invoice->setAttribute('payment_methods', $methods);
            $invoice->unsetRelation('payments');

            return $invoice;
        });

        return $this->paginated($invoices);
    }

    public function show(Request $request, int $invoice): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success($this->invoice(Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice)));
    }

    public function payment(Request $request, int $invoice, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $invoice = Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice);
        $data = $request->validate(['method' => ['required', Rule::enum(PaymentMethod::class)], 'amount' => ['required', 'numeric', 'gt:0'], 'reference_number' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string']]);

        return $this->success($service->recordPayment($invoice, $request->user(), $data), 'Payment recorded.');
    }

    public function void(Request $request, int $invoice, InvoiceService $service, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $invoice = Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $invoice = $service->void($invoice, $data['reason'], $request->user());
        $audit->record($invoice, 'billing.invoice_voided', $request->user(), $hotel->id, $invoice->outlet_id, ['reason' => $data['reason']]);

        return $this->success($invoice, 'Invoice voided.');
    }

    public function owners(Request $request): JsonResponse
    {
        return $this->success($this->ownerAccounts($request));
    }

    public function reopen(Request $request, int $invoice, InvoiceService $service, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $invoice = Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        $session = $service->reopen($invoice, $data['reason'], $request->user());
        $audit->record($invoice, 'billing.invoice_reopened', $request->user(), $hotel->id, $invoice->outlet_id, ['reason' => $data['reason']]);

        return $this->success($session, 'Bill reopened. Add or remove items, apply discount, then create the bill again.');
    }

    public function reprint(Request $request, int $invoice, InvoiceService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $invoice = Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice);

        return $this->success($service->reprint($invoice), 'Invoice reprint recorded.');
    }

    public function refund(Request $request, int $invoice, int $payment, PaymentService $service, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $invoice = Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:1000']]);

        $invoice = $service->refund($invoice, $invoice->payments()->findOrFail($payment), (float) $data['amount'], $data['reason'], $request->user());
        $audit->record($invoice, 'billing.refund', $request->user(), $hotel->id, $invoice->outlet_id, ['amount' => $data['amount'], 'reason' => $data['reason']]);

        return $this->success($this->invoice($invoice), 'Refund recorded.');
    }

    public function guest(Request $request, int $invoice, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $invoice = Invoice::query()->where('hotel_id', $hotel->id)->findOrFail($invoice);
        $guest = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_gstin' => ['nullable', 'string', 'max:20'],
        ]);
        if (! empty($guest['customer_id'])) {
            Customer::query()->where('hotel_id', $hotel->id)->findOrFail($guest['customer_id']);
        }

        return $this->success($this->invoice($service->attachGuestDetails($invoice, $guest)), 'Customer details saved.');
    }

    private function invoice(Invoice $invoice): Invoice
    {
        $invoice->load('outlet:id,name,code', 'customer:id,name,phone,email,gstin', 'creator:id,name', 'chargedTo:id,name', 'items.product:id,name,sku', 'payments.receiver:id,name', 'diningSession.diningTable');
        $refunded = $invoice->payments->where('type', 'refund')->groupBy('refunded_payment_id')->map(fn ($rows) => $rows->sum(fn ($payment) => abs(Money::toMinor($payment->amount))));
        $invoice->payments->each(function ($payment) use ($refunded): void {
            $payment->setAttribute('refunded_amount', Money::fromMinor((int) ($refunded[$payment->id] ?? 0)));
        });

        return $invoice;
    }

    /** @return array<int, array{id: int, name: string}> */
    private function ownerAccounts(Request $request): array
    {
        $hotel = $request->attributes->get('currentHotel');
        $roles = $hotel->roles()->get()->keyBy('id');

        return $hotel->users()
            ->wherePivot('is_active', true)
            ->orderBy('users.name')
            ->get()
            ->filter(fn (User $user) => (bool) $roles->get($user->pivot->role_id)?->is_owner)
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    private function ownerAccount(Request $request, int $userId): User
    {
        $owner = collect($this->ownerAccounts($request))->firstWhere('id', $userId);
        abort_unless($owner, 422, 'Select an owner from this hotel.');

        return User::query()->findOrFail($userId);
    }
}
