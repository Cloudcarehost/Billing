<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->paginated(Recipe::query()->where('hotel_id', $hotel->id)->with('product:id,name,unit', 'items.ingredient:id,name,unit,cost_price')->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, int $recipe): JsonResponse
    {
        return $this->save($request, $recipe);
    }

    private function save(Request $request, ?int $recipeId = null): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['product_id' => [$recipeId ? 'sometimes' : 'required', 'integer'], 'yield_quantity' => ['sometimes', 'numeric', 'gt:0'], 'is_active' => ['sometimes', 'boolean'], 'items' => ['sometimes', 'array', 'min:1'], 'items.*.ingredient_product_id' => ['required_with:items', 'integer', 'distinct'], 'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0']]);
        $recipe = $recipeId ? Recipe::query()->where('hotel_id', $hotel->id)->findOrFail($recipeId) : null;
        $productId = $data['product_id'] ?? $recipe?->product_id;
        Product::query()->where('hotel_id', $hotel->id)->findOrFail($productId);
        $ingredientIds = collect($data['items'] ?? [])->pluck('ingredient_product_id')->map(fn ($id) => (int) $id)->all();
        foreach ($ingredientIds as $ingredientId) {
            Product::query()->where('hotel_id', $hotel->id)->findOrFail($ingredientId);
            if ($ingredientId === (int) $productId) {
                abort(422, 'A recipe cannot consume itself as an ingredient.');
            }
        }
        if ($ingredientIds && $this->createsCycle($hotel->id, (int) $productId, $ingredientIds, $recipeId)) {
            abort(422, 'This recipe would create a circular ingredient loop.');
        }
        $recipe = DB::transaction(function () use ($hotel, $recipe, $productId, $data) {
            $recipe ??= Recipe::query()->create(['hotel_id' => $hotel->id, 'product_id' => $productId, 'yield_quantity' => $data['yield_quantity'] ?? 1, 'is_active' => $data['is_active'] ?? true]);
            $recipe->update(collect($data)->except('items')->all());
            if (array_key_exists('items', $data)) {
                $recipe->items()->delete();
                foreach ($data['items'] as $item) {
                    $recipe->items()->create(['ingredient_product_id' => $item['ingredient_product_id'], 'quantity' => $item['quantity']]);
                }
            }

            return $recipe;
        });

        return $this->success($recipe->fresh()->load('product:id,name,unit', 'items.ingredient:id,name,unit,cost_price'), $recipeId ? 'Recipe updated.' : 'Recipe created.', $recipeId ? 200 : 201);
    }

    /** @param list<int> $ingredientIds */
    private function createsCycle(int $hotelId, int $productId, array $ingredientIds, ?int $ignoreRecipeId): bool
    {
        $recipes = Recipe::query()->where('hotel_id', $hotelId)->when($ignoreRecipeId, fn ($query) => $query->whereKeyNot($ignoreRecipeId))->with('items')->get();
        $edges = [];
        foreach ($recipes as $recipe) {
            $edges[$recipe->product_id] = $recipe->items->pluck('ingredient_product_id')->map(fn ($id) => (int) $id)->all();
        }
        $edges[$productId] = $ingredientIds;

        foreach ($ingredientIds as $ingredientId) {
            if ($this->reaches($ingredientId, $productId, $edges, [])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, list<int>> $edges @param array<int, true> $seen */
    private function reaches(int $from, int $target, array $edges, array $seen): bool
    {
        if ($from === $target) {
            return true;
        }
        if (isset($seen[$from])) {
            return false;
        }
        $seen[$from] = true;
        foreach ($edges[$from] ?? [] as $next) {
            if ($this->reaches((int) $next, $target, $edges, $seen)) {
                return true;
            }
        }

        return false;
    }
}
