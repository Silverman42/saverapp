<?php

namespace App\Http\Middleware;

use App\Models\BusinessProfile;
use App\Services\BusinessSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCollectionsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $profile = BusinessProfile::current();
        abort_unless(config('collections.enabled'), 503, 'Cash collections are not enabled yet.');
        if ($profile->getAttribute('effective_configuration_id') !== null
            && in_array($request->route()?->getName(), ['customers.collections.create', 'customers.collections.preview', 'customers.collections.store'], true)) {
            app(BusinessSettings::class)->ensureFeature('collections');
            app(BusinessSettings::class)->ensureFeature('collection_cash');
        }

        return $next($request);
    }
}
