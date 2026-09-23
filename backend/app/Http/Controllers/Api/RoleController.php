<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthAccessCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('viewAny', [Role::class, $hotel]);

        return $this->success($hotel->roles()->with('permissions:id,name,code,group')->orderBy('name')->get());
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('manage', [User::class, $hotel]);

        $data = $this->validated($request, $hotel);
        $role = $hotel->roles()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
        ]);
        $role->permissions()->sync($data['permission_ids']);
        $audit->record($role, 'access.role_created', $request->user(), $hotel->id, null, ['permission_ids' => $data['permission_ids']]);
        app(AuthAccessCache::class)->forgetRole($role->id);

        return $this->success($role->load('permissions:id,name,code,group'), 'Role created.', 201);
    }

    public function update(Request $request, int $role, AuditService $audit): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $role = $hotel->roles()->findOrFail($role);
        Gate::authorize('update', $role);

        if ($role->is_owner) {
            return response()->json(['success' => false, 'message' => 'The Owner role is managed by the system.'], 422);
        }

        $data = $this->validated($request, $hotel, $role);
        $role->update([
            'name' => $data['name'] ?? $role->name,
            'slug' => $data['slug'] ?? $role->slug,
        ]);
        $role->permissions()->sync($data['permission_ids']);
        $audit->record($role, 'access.role_changed', $request->user(), $hotel->id, null, ['permission_ids' => $data['permission_ids']]);
        app(AuthAccessCache::class)->forgetRole($role->id);

        return $this->success($role->fresh()->load('permissions:id,name,code,group'), 'Role updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Hotel $hotel, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [$role === null ? 'required' : 'sometimes', 'string', 'max:80'],
            'slug' => [
                'sometimes', 'string', 'max:80', 'alpha_dash',
                Rule::unique('roles', 'slug')->where('hotel_id', $hotel->id)->ignore($role),
            ],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);
    }
}
