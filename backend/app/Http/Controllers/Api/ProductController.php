<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfillmentMode;
use App\Models\Category;
use App\Models\Hotel;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return $this->paginated($this->query($request)->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function search(Request $request): JsonResponse
    {
        return $this->success($this->query($request)->limit(min((int) $request->integer('limit', 20), 500))->get());
    }

    public function popular(Request $request, ReportService $reports): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);
        Outlet::query()->where('hotel_id', $hotel->id)->whereIn('id', $request->attributes->get('accessibleOutletIds', []))->findOrFail($data['outlet_id']);

        return $this->success($reports->popularProducts($hotel, (int) $data['outlet_id'], (int) ($data['days'] ?? 14), (int) ($data['limit'] ?? 8)));
    }

    public function store(Request $request, AuditService $audit, InventoryService $inventory): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $product = $hotel->products()->create($this->validated($request, $hotel));
        $inventory->ensureProductStocks($product);
        $audit->record($product, 'catalog.product_created', $request->user(), $hotel->id);

        return $this->success($product->load('category:id,name', 'kitchenStation:id,name,outlet_id'), 'Product created.', 201);
    }

    public function update(Request $request, int $product, AuditService $audit, InventoryService $inventory): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $product = $hotel->products()->findOrFail($product);
        $before = $product->only(['selling_price', 'cost_price', 'tax_rate']);
        $product->update($this->validated($request, $hotel, $product));
        $inventory->ensureProductStocks($product->fresh());
        if ($before !== $product->only(['selling_price', 'cost_price', 'tax_rate'])) {
            $audit->record($product, 'catalog.price_changed', $request->user(), $hotel->id, null, ['before' => $before, 'after' => $product->only(['selling_price', 'cost_price', 'tax_rate'])]);
        }

        return $this->success($product->fresh()->load('category:id,name', 'kitchenStation:id,name,outlet_id'), 'Product updated.');
    }

    private function query(Request $request)
    {
        $hotel = $request->attributes->get('currentHotel');
        $term = trim((string) $request->query('q', ''));

        return Product::query()->where('hotel_id', $hotel->id)->with('category:id,name', 'kitchenStation:id,name,outlet_id')
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($term !== '', fn ($q) => $q->where(fn ($search) => $search->where('name', 'like', "%{$term}%")->orWhere('sku', $term)->orWhere('barcode', $term)))
            ->orderBy('name');
    }

    private function validated(Request $request, Hotel $hotel, ?Product $product = null): array
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'], 'kitchen_station_id' => ['nullable', 'integer'],
            'fulfillment_mode' => ['sometimes', Rule::enum(FulfillmentMode::class)],
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:255'], 'short_description' => ['nullable', 'string', 'max:500'],
            'serving_size' => ['nullable', 'string', 'max:40'], 'sku' => ['nullable', 'string', 'max:80', Rule::unique('products', 'sku')->where('hotel_id', $hotel->id)->ignore($product)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->where('hotel_id', $hotel->id)->ignore($product)], 'hsn_code' => ['nullable', 'string', 'max:20'],
            'unit' => ['sometimes', 'string', 'max:20'], 'selling_price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'], 'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'price_includes_tax' => ['sometimes', 'boolean'], 'track_inventory' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'], 'is_quick' => ['sometimes', 'boolean'],
        ]);
        $categoryId = $data['category_id'] ?? $product?->category_id;
        if ($categoryId) {
            Category::query()->where('hotel_id', $hotel->id)->findOrFail($categoryId);
        }
        $mode = $data['fulfillment_mode'] ?? $product?->fulfillment_mode ?? FulfillmentMode::Direct->value;
        $stationId = array_key_exists('kitchen_station_id', $data) ? $data['kitchen_station_id'] : $product?->kitchen_station_id;
        if ($mode === FulfillmentMode::Direct->value) {
            $data['kitchen_station_id'] = null;
            $stationId = null;
        }
        if ($mode === FulfillmentMode::Kitchen->value && ! $stationId) {
            throw ValidationException::withMessages(['kitchen_station_id' => ['Select an active kitchen station for kitchen-prepared products.']]);
        }
        if ($stationId) {
            KitchenStation::query()->where('is_active', true)->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))->findOrFail($stationId);
        }

        $data['fulfillment_mode'] = $mode;
        if (! $product && ! array_key_exists('track_inventory', $data)) {
            $data['track_inventory'] = false;
        }
        if (! empty($data['is_quick'])) {
            $pinned = Product::query()->where('hotel_id', $hotel->id)->where('is_quick', true)->when($product, fn ($query) => $query->where('id', '!=', $product->id))->count();
            if ($pinned >= 12) {
                throw ValidationException::withMessages(['is_quick' => ['You can pin at most 12 items on the order screen.']]);
            }
        }

        return $data;
    }
}
