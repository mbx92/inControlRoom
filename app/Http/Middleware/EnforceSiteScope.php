<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSiteScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isAdmin()) {
            $request->attributes->set('enforced_site_ids', null);

            return $next($request);
        }

        $assignedIds = $user->accessibleSiteIds();

        // No assignments → unrestricted (backward compatible)
        if (empty($assignedIds)) {
            $request->attributes->set('enforced_site_ids', null);

            return $next($request);
        }

        $request->attributes->set('enforced_site_ids', $assignedIds);

        return $next($request);
    }
}
