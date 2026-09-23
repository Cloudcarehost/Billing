<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$requiredPermissions): Response
    {
        $role = $request->attributes->get('currentRole');
        $currentPermissions = $request->attributes->get('currentPermissions', []);

        if ($role === null || (! $role->is_owner && array_intersect($requiredPermissions, $currentPermissions) === [])) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }
}
