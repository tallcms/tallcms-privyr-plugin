<?php

declare(strict_types=1);

namespace Tallcms\Privyr\Providers;

use Illuminate\Support\ServiceProvider;
use TallCms\Cms\Services\SiteSettingsService;
use Tallcms\Privyr\Jobs\ForwardSubmissionToPrivyr;
use Tallcms\Privyr\Support\PrivyrWebhookUrl;

class PrivyrServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap plugin services.
     *
     * IMPORTANT: routes/Filament pages are wired by the plugin system and the
     * PrivyrPlugin Filament class — not here.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'tallcms-privyr');

        $this->registerContactSubmissionForwarder();
    }

    /**
     * Forward each contact submission to the site's Privyr webhook (async,
     * failure-tolerant), mirroring the Pro plugin's email-marketing observer.
     *
     * Uses rescue() so a form submission is never blocked. The webhook URL is
     * intentionally NOT passed into the queued job — only the site id and lead
     * data are. The job re-resolves the (secret) URL at run time.
     */
    protected function registerContactSubmissionForwarder(): void
    {
        try {
            $callback = function ($submission): void {
                rescue(function () use ($submission) {
                    $siteId = $submission->site_id;

                    // site_id is auto-stamped on frontend submissions by the
                    // multisite plugin; without it there is no per-site config.
                    if (! is_numeric($siteId)) {
                        return;
                    }
                    $siteId = (int) $siteId;

                    $url = app(SiteSettingsService::class)->getForSite($siteId, 'privyr_webhook_url');

                    // Gate-check only — avoids dispatching for sites with no/invalid
                    // webhook configured. The URL is never added to the payload.
                    if (! PrivyrWebhookUrl::passes(is_string($url) ? $url : null)) {
                        return;
                    }

                    ForwardSubmissionToPrivyr::dispatch(
                        $siteId,
                        $submission->name ?? null,
                        $submission->email ?? null,
                        $submission->form_data ?? [],
                        $submission->page_url ?? null,
                    );
                });
            };

            // Register `created` on each distinct submission class. The app may
            // expose `App\Models\TallcmsContactSubmission` either as a real
            // subclass (separate event) OR as a class_alias of the base (same
            // underlying class). Dedupe by the RESOLVED class name so an alias
            // doesn't register the same listener twice → duplicate leads.
            $candidates = [
                \TallCms\Cms\Models\TallcmsContactSubmission::class,
                'App\\Models\\TallcmsContactSubmission',
            ];

            $targets = [];
            foreach ($candidates as $class) {
                if (class_exists($class)) {
                    $canonical = (new \ReflectionClass($class))->getName();
                    $targets[$canonical] = $canonical;
                }
            }

            foreach ($targets as $class) {
                $class::created($callback);
            }
        } catch (\Throwable) {
            // Core CMS version may not ship the contact submission model.
        }
    }
}
