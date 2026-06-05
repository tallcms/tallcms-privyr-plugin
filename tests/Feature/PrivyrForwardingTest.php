<?php

namespace Tests\Feature;

use App\Models\TallcmsContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use TallCms\Cms\Services\SiteSettingsService;
use Tallcms\Multisite\Models\Site;
use Tallcms\Privyr\Jobs\ForwardSubmissionToPrivyr;
use Tests\TestCase;

class PrivyrForwardingTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_URL = 'https://www.privyr.com/api/v1/incoming-leads/aaaa/bbbb';

    private function makeSite(): Site
    {
        $user = User::factory()->create();

        return Site::create([
            'name' => 'Acme',
            'domain' => 'acme-'.Str::random(6).'.test',
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
        ]);
    }

    private function createSubmission(int $siteId, array $overrides = []): TallcmsContactSubmission
    {
        // site_id is NOT fillable (normally auto-stamped from the frontend
        // resolver) — set it explicitly via forceFill for the test.
        $submission = new TallcmsContactSubmission;
        $submission->forceFill(array_merge([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'form_data' => [
                ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => '+6591234567'],
                ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'value' => 'Hi'],
            ],
            'page_url' => 'https://acme.test/contact',
            'site_id' => $siteId,
        ], $overrides))->save();

        return $submission;
    }

    public function test_dispatches_job_when_site_has_valid_webhook(): void
    {
        Bus::fake([ForwardSubmissionToPrivyr::class]);

        $site = $this->makeSite();
        app(SiteSettingsService::class)->setForSite($site->id, 'privyr_webhook_url', self::VALID_URL, 'text');

        $this->createSubmission($site->id);

        Bus::assertDispatched(ForwardSubmissionToPrivyr::class);
    }

    public function test_does_not_dispatch_when_no_webhook_configured(): void
    {
        Bus::fake([ForwardSubmissionToPrivyr::class]);

        $site = $this->makeSite();

        $this->createSubmission($site->id);

        Bus::assertNotDispatched(ForwardSubmissionToPrivyr::class);
    }

    public function test_does_not_dispatch_when_stored_url_is_not_a_privyr_url(): void
    {
        Bus::fake([ForwardSubmissionToPrivyr::class]);

        $site = $this->makeSite();
        // Bypass the form validator to simulate a bad stored value.
        app(SiteSettingsService::class)->setForSite($site->id, 'privyr_webhook_url', 'https://evil.example/api', 'text');

        $this->createSubmission($site->id);

        Bus::assertNotDispatched(ForwardSubmissionToPrivyr::class);
    }

    public function test_job_posts_lossless_payload_to_resolved_url(): void
    {
        Http::fake([self::VALID_URL => Http::response(['success' => 'True'], 200)]);

        $site = $this->makeSite();
        app(SiteSettingsService::class)->setForSite($site->id, 'privyr_webhook_url', self::VALID_URL, 'text');

        (new ForwardSubmissionToPrivyr(
            $site->id,
            'Jane',
            'jane@example.com',
            [['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => '+6591234567']],
            'https://acme.test/contact',
        ))->handle(app(SiteSettingsService::class));

        Http::assertSent(function ($request) {
            return $request->url() === self::VALID_URL
                && $request['name'] === 'Jane'
                && $request['email'] === 'jane@example.com'
                && $request['phone'] === '+6591234567';
        });
    }

    public function test_job_throws_on_non_2xx_so_queue_retries(): void
    {
        Http::fake([self::VALID_URL => Http::response('error', 500)]);

        $site = $this->makeSite();
        app(SiteSettingsService::class)->setForSite($site->id, 'privyr_webhook_url', self::VALID_URL, 'text');

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        (new ForwardSubmissionToPrivyr($site->id, 'Jane', 'jane@example.com', [], null))
            ->handle(app(SiteSettingsService::class));
    }

    public function test_job_skips_when_no_webhook_configured(): void
    {
        Http::fake();

        $site = $this->makeSite();

        (new ForwardSubmissionToPrivyr($site->id, 'Jane', 'jane@example.com', [], null))
            ->handle(app(SiteSettingsService::class));

        Http::assertNothingSent();
    }
}
