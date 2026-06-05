# TallCMS Privyr

Forward TallCMS **Contact Form** submissions to each site's [Privyr](https://www.privyr.com)
CRM via Privyr's generic incoming-leads webhook.

When a visitor submits a Contact Form block, the lead is sent to the site's configured Privyr
webhook as a new lead — asynchronously, with retries, and without ever blocking the form.

## Features

- **Per-site configuration.** Each site owner pastes their own Privyr webhook URL on the
  plugin's **Privyr** settings page (under *Settings*). No access to Pro settings or plugin
  installation required for the customer.
- **Self-contained settings page.** A searchable Site selector (showing `Name (domain)`) lets
  owners with multiple sites choose which to configure; single-site owners are auto-selected.
- **Full lead payload.** Name, email, phone, and message are promoted to top-level keys for
  Privyr's auto-detection; every other form field is forwarded as its own labelled line
  (collision-safe), so nothing is lost.
- **Secure by default.** The webhook URL (which carries secret tokens) is validated to Privyr's
  host + `/api/v1/incoming-leads/` path (SSRF guard), is never placed in the queued job payload
  or logs, and is resolved at send time.
- **Resilient.** Forwarding runs on a queued job (`tries=3`, backoff `30/120/300s`). Failures
  are logged with the `site_id` (never the URL) and never affect the visitor's submission.

## Requirements

- TallCMS with the multisite plugin (provides per-site settings + site context).
- A running queue worker (`php artisan queue:work`) — the plugin dispatches a queued job.

## Installation

Upload the release zip via **Plugin Manager → Upload**, or place the plugin under
`plugins/tallcms/privyr/` and clear caches (`php artisan optimize:clear`).

## Configuration

1. In your Privyr account, go to **Integrations → Webhooks** and copy your webhook URL
   (`https://www.privyr.com/api/v1/incoming-leads/...`).
2. In the TallCMS admin, open **Settings → Privyr**, select the site, paste the URL, and save.
3. Submit a Contact Form on that site's frontend — the lead appears in Privyr.

Leave the URL blank to disable forwarding for a site.

## Development

The plugin ships no migrations or routes. Tests live under `tests/` and run inside a host
TallCMS app's test suite (they are `export-ignore`d from release archives so the marketplace
validator's file whitelist passes).
