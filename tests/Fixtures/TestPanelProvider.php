<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Tests\TestCase;

/**
 * A panel that attaches the plugin exactly as a host would.
 *
 * Nothing in this package registers globally, so a test that does not attach the
 * plugin sees no resources, no pages and no policies — which is the guarantee, and
 * a test asserts it.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('testing')
            ->default()
            ->path('testing')
            ->authGuard('web')
            ->plugin(
                PromotionsPlugin::make()
                    ->tenantUsing(fn (): string => TestCase::TENANT)
                    ->actorUsing(fn (): ?string => TestCase::$actor)
            );
    }
}
