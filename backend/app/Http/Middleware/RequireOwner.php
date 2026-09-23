<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->attributes->get('currentRole');

        if (! $role?->is_owner) {
            return response()->json([
                'success' => false,
                'message' => 'Only an Owner can manage roles and permissions.',
            ], 403);
        }

        return $next($request);
    }
}
