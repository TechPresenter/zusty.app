<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\AppContentService;
use Zuste\Services\TaxonomyService;

/**
 * The bundled option lists every client renders.
 *
 * One call returns every taxonomy — sort orders, platforms, languages,
 * genders, deliverables, industries, support topics — plus the numeric filter
 * bounds. The apps fetch this once on launch and cache it, so an admin
 * relabelling a chip reaches Android, iOS and Web with no code change.
 *
 * Conditional requests are supported: the payload carries a version stamp that
 * moves whenever any term is added, edited or removed, and that stamp is the
 * ETag. A client that already holds the current bundle gets a 304 and no body,
 * which keeps the cost of "check for changes on every launch" near zero.
 */
final class TaxonomyController extends BaseApiController
{
    /** GET /app/onboarding — the intro slides shown before sign-up. */
    public function onboarding(Request $request): never
    {
        $audience = $request->string('audience', 'all');
        $audience = in_array($audience, ['brands', 'creators'], true) ? $audience : 'all';

        Response::success([
            'slides' => AppContentService::onboardingSlides($audience),
        ]);
    }

    /** GET /app/taxonomies */
    public function index(Request $request): never
    {
        $bundle = TaxonomyService::bundle();
        $etag   = '"' . $bundle['version'] . '"';

        // Strip any weak-validator prefix and surrounding quotes before
        // comparing, so a proxy that rewrites the header does not defeat this.
        $ifNoneMatch = trim($request->header('if-none-match'));

        if ($ifNoneMatch !== '' && $this->matchesEtag($ifNoneMatch, $etag)) {
            Response::header('ETag', $etag);
            Response::header('Cache-Control', 'private, max-age=0, must-revalidate');
            Response::json([], 304);
        }

        Response::header('ETag', $etag);
        Response::header('Cache-Control', 'private, max-age=0, must-revalidate');

        Response::success($bundle);
    }

    /**
     * GET /app/taxonomies/{key} — one list, for clients that only need a
     * single dropdown and would rather not hold the whole bundle.
     */
    public function show(Request $request): never
    {
        $key   = (string) $request->routeParam('key', '');
        $terms = TaxonomyService::terms($key);

        Response::success([
            'key'   => $key,
            'terms' => $terms,
            'count' => count($terms),
        ]);
    }

    // matchesEtag() now lives on BaseApiController: /app/config answers 304 the
    // same way, and two private copies of the same comparison would eventually
    // drift apart.
}
