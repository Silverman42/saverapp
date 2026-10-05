<?php

namespace App\Http\Middleware;

use App\Models\BusinessProfile;
use App\Services\BusinessSettings;
use App\Services\CollectionMethodCatalogue;
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
        abort_unless(config('collections.enabled'), 503, 'Collections are not enabled yet.');
        if ($profile->getAttribute('effective_configuration_id') !== null
            && in_array($request->route()?->getName(), ['customers.collections.create', 'customers.collections.preview', 'customers.collections.store'], true)) {
            app(BusinessSettings::class)->ensureFeature('collections');
            abort_unless(app(BusinessSettings::class)->featureEnabled('collection_cash') || app(CollectionMethodCatalogue::class)->available() !== [],
                503, 'No collection method is enabled.');
        }

        return $next($request);
    }
}
