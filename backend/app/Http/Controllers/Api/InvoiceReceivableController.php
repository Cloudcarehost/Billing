<?php

namespace App\Http\Controllers\Api;

use App\Models\InvoiceReceivable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceReceivableController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'settled', 'voided'])],
            'type' => ['nullable', Rule::in(['customer_pending', 'owner_account'])],
            'assigned_to_user_id' => ['nullable', 'integer'],
            'outlet_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));
        $rows = InvoiceReceivable::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))
            ->when($data['outlet_id'] ?? null, fn ($query, $id) => $query->where('outlet_id', $id))
            ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($data['assigned_to_user_id'] ?? null, fn ($query, $id) => $query->where('assigned_to_user_id', $id))
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('note', 'like', $term)
                        ->orWhereHas('invoice', fn ($invoice) => $invoice
                            ->where('invoice_number', 'like', $term)
                            ->orWhere('customer_name', 'like', $term)
                            ->orWhere('customer_phone', 'like', $term)
                            ->orWhereHas('diningSession.diningTable', fn ($table) => $table->where('name', 'like', $term)));
                });
            })
            ->with([
                'assignee:id,name', 'opener:id,name', 'settler:id,name', 'outlet:id,name',
                'invoice:id,outlet_id,dining_session_id,charged_to_user_id,invoice_number,status,payment_status,business_date,billed_at,customer_name,customer_phone,total_amount,paid_amount,balance_amount',
                'invoice.chargedTo:id,name', 'invoice.diningSession:id,dining_table_id', 'invoice.diningSession.diningTable:id,name,service_type',
                'invoice.payments:id,invoice_id,method,type,amount,paid_at',
            ])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('opened_at')
            ->paginate(min((int) ($data['per_page'] ?? 10), 50));

        return $this->paginated($rows);
    }
}
