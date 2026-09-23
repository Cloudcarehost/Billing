<?php

namespace App\Http\Controllers\Api;

use App\Models\KitchenStation;
use App\Models\Outlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KitchenStationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success(KitchenStation::query()->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))->when($request->attributes->get('currentOutletId'), fn ($q, $id) => $q->where('outlet_id', $id))->with('outlet:id,name,code')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $this->validated($request, $hotel);
        $station = KitchenStation::query()->create($data);

        return $this->success($station->load('outlet:id,name,code'), 'Kitchen station created.', 201);
    }

    public function update(Request $request, int $kitchenStation): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $station = KitchenStation::query()->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))->findOrFail($kitchenStation);
        $station->update($this->validated($request, $hotel, $station));

        return $this->success($station->fresh()->load('outlet:id,name,code'), 'Kitchen station updated.');
    }

    public function destroy(Request $request, int $kitchenStation): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $station = KitchenStation::query()->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))->findOrFail($kitchenStation);
        $station->delete();

        return $this->success(['id' => $station->id], 'Kitchen station deleted.');
    }

    private function validated(Request $request, $hotel, ?KitchenStation $station = null): array
    {
        $data = $request->validate([
            'outlet_id' => [$station ? 'sometimes' : 'required', 'integer'], 'name' => [$station ? 'sometimes' : 'required', 'string', 'max:80'],
            'code' => [$station ? 'sometimes' : 'required', 'alpha_dash', 'max:30'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $outletId = $data['outlet_id'] ?? $station?->outlet_id;
        Outlet::query()->where('hotel_id', $hotel->id)->whereIn('id', request()->attributes->get('accessibleOutletIds', []))->findOrFail($outletId);
        $code = $data['code'] ?? $station?->code;
        $data['code'] = $code;
        validator($data, ['code' => [Rule::unique('kitchen_stations', 'code')->where('outlet_id', $outletId)->ignore($station)]])->validate();

        return $data;
    }
}
