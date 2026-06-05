<?php

namespace Tests\Unit;

use Tallcms\Privyr\Support\PrivyrWebhookUrl;
use Tests\TestCase;

/**
 * Lives in the app test suite so it runs with `php artisan test`; the class
 * under test is autoloaded by the plugin system. Moves into the plugin's own
 * suite if/when the plugin is extracted to its own repo.
 */
class PrivyrWebhookUrlTest extends TestCase
{
    public function test_accepts_valid_privyr_urls(): void
    {
        $this->assertTrue(PrivyrWebhookUrl::passes('https://www.privyr.com/api/v1/incoming-leads/abc/def'));
        $this->assertTrue(PrivyrWebhookUrl::passes('https://privyr.com/api/v1/incoming-leads/abc/def'));
        // Surrounding whitespace is tolerated.
        $this->assertTrue(PrivyrWebhookUrl::passes('  https://www.privyr.com/api/v1/incoming-leads/x  '));
    }

    public function test_rejects_blank_values(): void
    {
        $this->assertFalse(PrivyrWebhookUrl::passes(null));
        $this->assertFalse(PrivyrWebhookUrl::passes(''));
        $this->assertFalse(PrivyrWebhookUrl::passes('   '));
    }

    public function test_rejects_wrong_scheme_host_or_path(): void
    {
        // http (not https)
        $this->assertFalse(PrivyrWebhookUrl::passes('http://www.privyr.com/api/v1/incoming-leads/x'));
        // wrong host (incl. look-alike / subdomain spoof)
        $this->assertFalse(PrivyrWebhookUrl::passes('https://evil.com/api/v1/incoming-leads/x'));
        $this->assertFalse(PrivyrWebhookUrl::passes('https://privyr.com.evil.com/api/v1/incoming-leads/x'));
        // wrong path
        $this->assertFalse(PrivyrWebhookUrl::passes('https://www.privyr.com/api/v1/other/x'));
        // not a url
        $this->assertFalse(PrivyrWebhookUrl::passes('not a url'));
    }
}
