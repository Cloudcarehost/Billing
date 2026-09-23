<?php

namespace App\Http\Controllers\Api;

use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->paginated(Supplier::query()->where('hotel_id', $hotel->id)->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->toString().'%'))->orderBy('name')->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success(Supplier::query()->create(['hotel_id' => $hotel->id, ...$this->data($request)]), 'Supplier created.', 201);
    }

    public function update(Request $request, int $supplier): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $supplier = Supplier::query()->where('hotel_id', $hotel->id)->findOrFail($supplier);
        $supplier->update($this->data($request, true));

        return $this->success($supplier->fresh(), 'Supplier updated.');
    }

    private function data(Request $request, bool $partial = false): array
    {
        return $request->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'gstin' => ['nullable', 'string', 'max:20'], 'address' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'is_active' => ['sometimes', 'boolean']]);
    }
}
