<?php

declare(strict_types=1);

namespace Tallcms\Privyr\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a Privyr generic-webhook URL.
 *
 * This feature performs server-side POSTs to a stored URL, so we constrain the
 * destination tightly (SSRF guard): https only, Privyr hosts only, and the
 * documented incoming-leads path. Used on the settings form AND re-checked in
 * the job before sending.
 */
class PrivyrWebhookUrl implements ValidationRule
{
    /** @var list<string> */
    public const ALLOWED_HOSTS = ['privyr.com', 'www.privyr.com'];

    public const PATH_PREFIX = '/api/v1/incoming-leads/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Blank disables forwarding — leave that to the field's nullable handling.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! static::passes(is_string($value) ? $value : null)) {
            $fail('Enter a valid Privyr webhook URL (https://www.privyr.com/api/v1/incoming-leads/...).');
        }
    }

    /**
     * Blank/whitespace-safe check. Returns false for null/empty so callers can
     * use it as a single "is this a usable Privyr webhook?" gate.
     */
    public static function passes(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        if (! in_array(strtolower($parts['host']), self::ALLOWED_HOSTS, true)) {
            return false;
        }

        return str_starts_with($parts['path'], self::PATH_PREFIX);
    }
}
