<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success($hotel->categories()->orderBy('sort_order')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $category = $hotel->categories()->create($this->validated($request, $hotel));

        return $this->success($category, 'Category created.', 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $category = $hotel->categories()->findOrFail($category);
        $category->update($this->validated($request, $hotel, $category));

        return $this->success($category->fresh(), 'Category updated.');
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $category = $hotel->categories()->findOrFail($category);
        $category->delete();

        return $this->success(['id' => $category->id], 'Category deleted.');
    }

    private function validated(Request $request, Hotel $hotel, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        if (isset($data['slug'])) {
            validator($data, ['slug' => [Rule::unique('categories', 'slug')->where('hotel_id', $hotel->id)->ignore($category)]])->validate();
        }

        return $data;
    }
}
