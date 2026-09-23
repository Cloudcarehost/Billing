<?php

namespace App\Http\Controllers\Api;

use App\Enums\OutletOrderFlow;
use App\Events\RestaurantUpdated;
use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\TablePublicLink;
use App\Services\AuthAccessCache;
use App\Services\DiningBillingService;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OutletController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('view', $hotel);

        return $this->success($hotel->outlets()->whereIn('id', $request->attributes->get('accessibleOutletIds', []))->orderBy('name')->get());
    }

    public function store(Request $request, InventoryService $inventory): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('update', $hotel);

        $data = $this->validated($request, $hotel);
        $outlet = $hotel->outlets()->create($data);
        $inventory->ensureOutletStocks($outlet);
        app(AuthAccessCache::class)->forgetHotel($hotel->id);

        return $this->success($outlet, 'Outlet created.', 201);
    }

    public function update(Request $request, int $outlet, DiningBillingService $billing): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $outlet = $hotel->outlets()->findOrFail($outlet);
        Gate::authorize('update', $outlet);

        $previousFlow = $outlet->order_flow;
        $outlet->update($this->validated($request, $hotel, $outlet));
        app(AuthAccessCache::class)->forgetHotel($hotel->id);
        $outlet = $outlet->fresh();
        if ($outlet && $outlet->order_flow !== $previousFlow) {
            if ($outlet->order_flow === OutletOrderFlow::DirectBill->value) {
                $billing->moveOpenKitchenItemsToBill($outlet, $request->user());
            } else {
                RestaurantUpdated::dispatch('outlet_flow_changed', $outlet->hotel_id, $outlet->id, null, null, null, null, null, null, [
                    'order_flow' => $outlet->order_flow,
                    'moved_item_count' => 0,
                ]);
            }
        }

        return $this->success($outlet, 'Outlet updated.');
    }

    public function destroy(Request $request, int $outlet): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $outlet = $hotel->outlets()->findOrFail($outlet);
        Gate::authorize('delete', $outlet);

        if ($hotel->outlets()->count() <= 1) {
            throw ValidationException::withMessages([
                'outlet' => ['Keep at least one outlet for this hotel.'],
            ]);
        }

        if ($outlet->diningSessions()->exists() || $outlet->invoices()->exists()) {
            throw ValidationException::withMessages([
                'outlet' => ['This outlet has table or bill history. Uncheck Active outlet instead of deleting it.'],
            ]);
        }

        try {
            DB::transaction(function () use ($outlet): void {
                if (Schema::hasTable('hotel_user_outlets')) {
                    DB::table('hotel_user_outlets')->where('outlet_id', $outlet->id)->delete();
                }
                $tableIds = $outlet->diningTables()->pluck('id');
                if ($tableIds->isNotEmpty()) {
                    TablePublicLink::query()->whereIn('dining_table_id', $tableIds)->delete();
                }
                $outlet->diningTables()->delete();
                $outlet->delete();
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'outlet' => ['This outlet still has related records. Uncheck Active outlet instead of deleting it.'],
            ]);
        }

        app(AuthAccessCache::class)->forgetHotel($hotel->id);

        return $this->success(['id' => $outlet->id], 'Outlet deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Hotel $hotel, ?Outlet $outlet = null): array
    {
        return $request->validate([
            'name' => [$outlet === null ? 'required' : 'sometimes', 'string', 'max:255'],
            'code' => [
                $outlet === null ? 'required' : 'sometimes', 'alpha_dash', 'max:30',
                Rule::unique('outlets', 'code')->where('hotel_id', $hotel->id)->ignore($outlet),
            ],
            'invoice_prefix' => [
                $outlet === null ? 'required' : 'sometimes', 'alpha_dash', 'max:20',
                Rule::unique('outlets', 'invoice_prefix')->where('hotel_id', $hotel->id)->ignore($outlet),
            ],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'order_flow' => ['sometimes', Rule::in(OutletOrderFlow::values())],
        ]);
    }
}
