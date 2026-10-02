<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Request;
use Zuste\Services\SitemapService;

/**
 * Sitemap generator.
 *
 * Reading the screen needs `cms.view`; writing anything into the web root needs
 * `cms.pages`, because a published sitemap is public content.
 */
final class SitemapController extends BaseAdminController
{
    /** GET /admin/sitemap */
    public function index(Request $request): never
    {
        $this->authorize($request, 'cms.view');

        // Counts and toggles only touch the database, so they always render.
        $sources = SitemapService::sources();
        $lastRun = SitemapService::state();

        $problems = [];
        $warnings = [];
        $status   = null;
        $preview  = [];
        $robots   = '';

        // A missing or malformed canonical base does not stop a run — it falls
        // back to APP_URL — so the operator has to be told before localhost
        // URLs end up in a published file.
        $baseUrlWarning = SitemapService::baseUrlWarning();

        if ($baseUrlWarning !== null) {
            $warnings[] = $baseUrlWarning;
        }

        // Everything below needs a usable base URL and a writable web root.
        // If either is missing the screen still has to explain why, rather than
        // dying on an exception page.
        try {
            $status  = SitemapService::status();
            $preview = SitemapService::build(50);
            $robots  = SitemapService::robotsTxt();
        } catch (\Throwable $e) {
            $problems[] = $e->getMessage();
        }

        $pending = 0;
        foreach ($sources as $source) {
            if ($source['enabled']) {
                $pending += $source['count'];
            }
        }

        if ($status !== null && !$status['writable']) {
            $problems[] = 'The web root is not writable: ' . $status['directory'];
        }

        $this->view($request, 'admin.sitemap.index', [
            'pageTitle'    => 'Sitemap',
            'pageSubtitle' => 'Generate sitemap.xml and robots.txt for the public site.',
            'sources'      => $sources,
            'status'       => $status,
            'lastRun'      => $lastRun,
            'preview'      => $preview,
            'robotsBody'   => $robots,
            'problems'     => $problems,
            'warnings'     => $warnings,
            'pendingTotal' => $pending,
            'frequencies'  => SitemapService::CHANGE_FREQUENCIES,
            'staticPaths'  => implode("\n", SitemapService::staticPaths()),
            'robotsPaths'  => implode("\n", SitemapService::robotsDisallow()),
            'homePriority' => SitemapService::priority('home'),
            'homeFreq'     => SitemapService::changefreq('home'),
            'maxPerFile'   => SitemapService::MAX_URLS_PER_FILE,
        ]);
    }

    /** POST /admin/sitemap/generate */
    public function generate(Request $request): never
    {
        $this->authorize($request, 'cms.pages');

        // A large catalogue takes a while to walk; the default web limit would
        // abort the write half way through.
        set_time_limit(300);

        $result  = SitemapService::writeXml();
        $summary = SitemapService::changeSummary($result);

        $this->log(
            $request,
            'sitemap.generated',
            'sitemap',
            null,
            $summary,
            [
                'total'    => $result['total'],
                'counts'   => $result['counts'],
                'changes'  => $result['changes'],
                'files'    => array_column($result['files'], 'name'),
                'removed'  => $result['removed'],
                'is_index' => $result['is_index'],
            ]
        );

        $this->respond($request, $summary, '/admin/sitemap', [
            'total'   => $result['total'],
            'files'   => $result['files'],
            'changes' => $result['changes'],
        ]);
    }

    /** POST /admin/sitemap/settings */
    public function saveSettings(Request $request): never
    {
        $this->authorize($request, 'cms.pages');

        $changed = SitemapService::saveConfiguration(
            $request->array('include'),
            $request->array('changefreq'),
            $request->array('priority'),
            $request->string('static_paths'),
            $request->string('robots_disallow')
        );

        if ($changed === []) {
            $this->respond($request, 'No changes to save.', '/admin/sitemap');
        }

        $this->log(
            $request,
            'sitemap.settings_updated',
            'settings',
            null,
            count($changed) . ' sitemap setting(s) updated.',
            ['keys' => $changed]
        );

        $this->respond(
            $request,
            count($changed) . ' setting' . (count($changed) === 1 ? '' : 's') . ' saved. Generate again to publish the change.',
            '/admin/sitemap'
        );
    }

    /** POST /admin/sitemap/robots */
    public function writeRobots(Request $request): never
    {
        $this->authorize($request, 'cms.pages');

        $result = SitemapService::writeRobots();

        $this->log(
            $request,
            'sitemap.robots_written',
            'sitemap',
            null,
            'Wrote robots.txt (' . $result['bytes'] . ' bytes).',
            ['url' => $result['url']]
        );

        $this->respond($request, 'robots.txt written to the web root.', '/admin/sitemap');
    }
}
