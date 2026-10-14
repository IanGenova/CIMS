<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Give the listing screens a sane ordering when the caller did not ask for one.
 *
 * Every list controller in this application reads SortField and SortType off
 * the request and hands them straight to orderBy(). The grids always send both,
 * so nothing noticed -- but anything else calling the same endpoint (a report
 * link, an integration, a saved URL, a hand-built request) got
 * "Order direction must be asc or desc" and a 500, which reads as a broken
 * screen rather than a missing parameter.
 *
 * Filling the gap here rather than in thirty controllers keeps the default in
 * one place. A caller that does send them is untouched.
 */
class DefaultListParameters
{
    public function handle($request, Closure $next)
    {
        $defaults = [];

        if (! $request->filled('SortField')) {
            $defaults['SortField'] = 'id';
        }

        // Anything that is not a direction is treated as absent. MySQL takes
        // only these two, and passing anything else through is the 500.
        if (! in_array(strtolower((string) $request->input('SortType')), ['asc', 'desc'], true)) {
            $defaults['SortType'] = 'desc';
        }

        if (! $request->filled('limit')) {
            $defaults['limit'] = 10;
        }

        if (! $request->filled('page')) {
            $defaults['page'] = 1;
        }

        if ($defaults) {
            $request->merge($defaults);
        }

        return $next($request);
    }
}
