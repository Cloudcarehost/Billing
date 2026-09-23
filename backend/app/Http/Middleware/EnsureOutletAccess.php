<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOutletAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessibleIds = collect($request->attributes->get('accessibleOutletIds', []));
        $hasHotelWideAccess = (bool) $request->attributes->get('hasHotelWideOutletAccess', false);

        $requested = $request->header('X-Outlet-Id') ?? $request->query('outlet_id');
        if ($requested !== null && filter_var($requested, FILTER_VALIDATE_INT) === false) {
            return response()->json(['success' => false, 'message' => 'The outlet context is invalid.'], 422);
        }
        if ($requested !== null && ! $accessibleIds->contains((int) $requested)) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this outlet.'], 403);
        }

        $currentOutletId = $requested !== null ? (int) $requested : (! $hasHotelWideAccess ? $accessibleIds->first() : null);
        $request->attributes->set('currentOutletId', $currentOutletId);
        $request->attributes->set('accessibleOutletIds', $accessibleIds->map(fn ($id) => (int) $id)->all());
        $request->attributes->set('hasHotelWideOutletAccess', $hasHotelWideAccess);

        return $next($request);
    }
}
