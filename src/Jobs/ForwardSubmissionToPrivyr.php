<?php

declare(strict_types=1);

namespace Tallcms\Privyr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TallCms\Cms\Services\SiteSettingsService;
use Tallcms\Privyr\Support\PrivyrPayload;
use Tallcms\Privyr\Support\PrivyrWebhookUrl;

class ForwardSubmissionToPrivyr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<int, array<string, mixed>>  $formData
     */
    public function __construct(
        protected int $siteId,
        protected ?string $name,
        protected ?string $email,
        protected array $formData,
        protected ?string $pageUrl,
    ) {}

    public function handle(SiteSettingsService $settings): void
    {
        // Resolve the secret webhook URL HERE — it is never carried in the
        // queued payload or failed-job records.
        $url = $settings->getForSite($this->siteId, 'privyr_webhook_url');
        $url = is_string($url) ? $url : null;

        // Empty (forwarding disabled) or somehow invalid: nothing to do, no retry.
        if (! PrivyrWebhookUrl::passes($url)) {
            return;
        }

        $payload = PrivyrPayload::fromSubmissionData(
            $this->name,
            $this->email,
            $this->formData,
            $this->pageUrl,
        );

        // ->throw() surfaces non-2xx responses so the queue retries with backoff.
        Http::asJson()
            ->timeout(15)
            ->post($url, $payload)
            ->throw();
    }

    public function failed(\Throwable $exception): void
    {
        // Never log the webhook URL — it contains secret path tokens.
        Log::warning('Privyr forward failed', [
            'site_id' => $this->siteId,
            'email' => $this->email,
            'error' => $exception->getMessage(),
        ]);
    }
}
