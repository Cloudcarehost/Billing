<?php

namespace App\Http\Controllers\Api;

use App\Models\DiningTable;
use App\Models\TablePublicLink;
use App\Services\AuditService;
use App\Services\TablePublicLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TablePublicLinkController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $tables = $this->tables($request)->with('publicLink')->get();

        return $this->success($tables->map(fn (DiningTable $table) => $this->present($table))->values());
    }

    public function store(Request $request, int $table, TablePublicLinkService $links, AuditService $audit): JsonResponse
    {
        $diningTable = $this->tables($request)->findOrFail($table);
        $link = $links->createForTable($diningTable);
        $audit->record($link, 'table_qr_generated', $request->user(), $request->attributes->get('currentHotel')->id, $diningTable->outlet_id);

        return $this->success($this->present($diningTable->setRelation('publicLink', $link)), 'Table QR is ready.', 201);
    }

    public function rotate(Request $request, int $table, TablePublicLinkService $links, AuditService $audit): JsonResponse
    {
        $diningTable = $this->tables($request)->with('publicLink')->findOrFail($table);
        $link = $diningTable->publicLink ? $links->rotate($diningTable->publicLink) : $links->createForTable($diningTable);
        $audit->record($link, 'table_qr_rotated', $request->user(), $request->attributes->get('currentHotel')->id, $diningTable->outlet_id);

        return $this->success($this->present($diningTable->setRelation('publicLink', $link)), 'Old QR disabled; print the new QR.');
    }

    public function update(Request $request, int $table, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $diningTable = $this->tables($request)->with('publicLink')->findOrFail($table);
        abort_unless($diningTable->publicLink, 404);
        $diningTable->publicLink->update($data);
        $audit->record($diningTable->publicLink, $data['is_active'] ? 'table_qr_enabled' : 'table_qr_disabled', $request->user(), $request->attributes->get('currentHotel')->id, $diningTable->outlet_id);

        return $this->success($this->present($diningTable->fresh('outlet:id,name', 'publicLink')), $data['is_active'] ? 'Table QR enabled.' : 'Table QR disabled.');
    }

    private function tables(Request $request)
    {
        $hotel = $request->attributes->get('currentHotel');

        return DiningTable::query()->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))
            ->where(fn ($query) => $query->where('service_type', 'dine_in')->orWhereNull('service_type'))
            ->with('outlet:id,name')->orderBy('outlet_id')->orderBy('sort_order')->orderBy('name');
    }

    private function present(DiningTable $table): array
    {
        $link = $table->publicLink;

        return [
            'table' => ['id' => $table->id, 'name' => $table->name, 'code' => $table->code, 'outlet' => $table->outlet?->name],
            'is_active' => $link?->is_active ?? false,
            'token' => $link?->token_encrypted,
            'rotated_at' => $link?->rotated_at?->toISOString(),
            'created_at' => $link?->created_at?->toISOString(),
        ];
    }
}
