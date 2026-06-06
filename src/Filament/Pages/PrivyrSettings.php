<?php

declare(strict_types=1);

namespace Tallcms\Privyr\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use TallCms\Cms\Services\SiteSettingsService;
use Tallcms\Privyr\Support\PrivyrWebhookUrl;

/**
 * Customer-facing, per-site Privyr configuration.
 *
 * No HasPageShield: like the Billing page, this is visible to site owners by
 * default. The page is self-contained — it carries its own Site selector (the
 * user's owned sites; all sites for super_admin) so it does NOT depend on the
 * dashboard's site picker widget being present. The default selection follows
 * the dashboard scope when set, otherwise the user's first owned site (the same
 * role-based fallback ThemeManager uses for site_owners).
 *
 * Works on both multisite and standalone installs: the Site model is resolved
 * at runtime (multisite's model when present, else core CMS's), since both
 * expose the same `name`/`domain`/`user_id`/`is_active` columns on the shared
 * `tallcms_sites` table. A standalone install simply has one site row.
 */
class PrivyrSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Privyr';

    protected static ?string $title = 'Privyr Integration';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 102;

    protected string $view = 'tallcms-privyr::filament.pages.privyr-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $siteId = $this->defaultSiteId();

        $this->form->fill([
            'site_id' => $siteId,
            'privyr_webhook_url' => $siteId
                ? app(SiteSettingsService::class)->getForSite($siteId, 'privyr_webhook_url')
                : null,
        ]);
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        $options = $this->siteOptions();

        return [
            Select::make('site_id')
                ->label('Site')
                ->options($options)
                ->searchable()
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated()
                ->disabled(count($options) <= 1)
                ->visible(! empty($options))
                ->helperText(count($options) > 1 ? 'Choose which site to configure.' : null)
                // When the site changes, load that site's saved webhook URL.
                ->afterStateUpdated(function ($state, callable $set): void {
                    $set('privyr_webhook_url', ($state && $this->canUseSite((int) $state))
                        ? app(SiteSettingsService::class)->getForSite((int) $state, 'privyr_webhook_url')
                        : null);
                }),

            TextInput::make('privyr_webhook_url')
                ->label('Privyr Webhook URL')
                ->placeholder('https://www.privyr.com/api/v1/incoming-leads/...')
                ->helperText('Paste the webhook URL from Privyr → Integrations → Webhooks. Contact form submissions on the selected site will be sent to Privyr as new leads. Leave blank to disable.')
                ->rule(new PrivyrWebhookUrl)
                ->nullable()
                ->maxLength(2048)
                ->visible(! empty($options)),
        ];
    }

    public function getSubheading(): ?string
    {
        if (empty($this->siteOptions())) {
            return 'Create a site first — Privyr forwarding is configured per site.';
        }

        return 'Contact form submissions for the selected site are sent to Privyr as new leads.';
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $siteId = (int) ($data['site_id'] ?? 0) ?: (int) ($this->defaultSiteId() ?? 0);

        if (! $siteId || ! $this->canUseSite($siteId)) {
            Notification::make()
                ->title('No site selected')
                ->body('Choose a site to configure before saving.')
                ->warning()
                ->send();

            return;
        }

        $url = trim((string) ($data['privyr_webhook_url'] ?? ''));
        $service = app(SiteSettingsService::class);

        if ($url === '') {
            $service->resetForSite($siteId, 'privyr_webhook_url');
        } else {
            $service->setForSite($siteId, 'privyr_webhook_url', $url, 'text');
        }

        Notification::make()
            ->title('Saved')
            ->body($url === ''
                ? 'Privyr forwarding disabled for this site.'
                : 'Privyr webhook saved. New contact submissions will be sent to Privyr.')
            ->success()
            ->send();
    }

    /**
     * Sites the current user may configure: their own, or all for super_admin.
     *
     * @return array<int, string>  [site_id => name]
     */
    protected function siteOptions(): array
    {
        $query = $this->siteModel()::query()->where('is_active', true)->orderBy('name');

        if (! $this->isSuperAdmin()) {
            $query->where('user_id', auth()->id());
        }

        // Label by name + domain — names can collide across sites, domains can't.
        return $query->get(['id', 'name', 'domain'])
            ->mapWithKeys(fn (Model $site): array => [
                $site->id => trim(($site->name ?? 'Untitled').' ('.$site->domain.')'),
            ])
            ->all();
    }

    /**
     * The Site model class to query. Prefer the multisite plugin's model (it may
     * carry global scopes / per-tenant behaviour) when installed; otherwise fall
     * back to the core CMS model so the page works on standalone installs.
     *
     * @return class-string<Model>
     */
    protected function siteModel(): string
    {
        return class_exists(\Tallcms\Multisite\Models\Site::class)
            ? \Tallcms\Multisite\Models\Site::class
            : \TallCms\Cms\Models\Site::class;
    }

    /**
     * Default site: the dashboard scope when valid/allowed, otherwise the first
     * site the user may configure (role-based fallback for site_owners).
     */
    protected function defaultSiteId(): ?int
    {
        $options = $this->siteOptions();

        if (empty($options)) {
            return null;
        }

        $sessionValue = session('multisite_admin_site_id');
        if (is_numeric($sessionValue) && array_key_exists((int) $sessionValue, $options)) {
            return (int) $sessionValue;
        }

        return (int) array_key_first($options);
    }

    protected function canUseSite(int $siteId): bool
    {
        return array_key_exists($siteId, $this->siteOptions());
    }

    protected function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user && method_exists($user, 'hasRole') && $user->hasRole('super_admin');
    }
}
