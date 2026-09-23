<?php

namespace App\Http\Controllers\Api;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PermissionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', [User::class, $request->attributes->get('currentHotel')]);

        return $this->success(Permission::query()->orderBy('group')->orderBy('name')->get());
    }
}
