<?php

declare(strict_types=1);

namespace Tallcms\Privyr\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Tallcms\Privyr\Filament\Pages\PrivyrSettings;

class PrivyrPlugin implements Plugin
{
    public function getId(): string
    {
        return 'tallcms-privyr';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            PrivyrSettings::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }
}
