<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Tests\Fixtures\TestPanelProvider;
use Liberu\Ecommerce\Promotions\PromotionsServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;
use Livewire\LivewireServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends PackageTestCase
{
    use UsesTestUser;

    public const TENANT = 'merchant-1';

    /** Who the panel reports as acting, so a test can prove the actor reaches the log. */
    public static ?string $actor = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$actor = 'staff-1';

        Filament::setCurrentPanel('testing');
    }

    /**
     * **Order matters, and it is not stylistic. Do not tidy this.**
     *
     * `filament/support`'s provider re-`bind()`s Livewire's `DataStore`, and a
     * `bind()` drops whatever instance was already registered under that key.
     * Register Livewire first and every component lookup afterwards gets a fresh,
     * empty store — which surfaces during render as
     * `ViewErrorBag::put(): $bag must be MessageBag, null given`, an error about
     * validation bags thrown nowhere near the provider ordering that caused it.
     *
     * Filament's providers therefore come before Livewire's, and the domain
     * package's provider is named here rather than duplicating the domain
     * dependency into `require-dev`: `PackageTestCase::getPackageProviders()` boots
     * a sibling's manifest provider only for a dev requirement, and `composer
     * validate` — which the Install workflow runs — warns on a package listed in
     * both sections.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            FilamentServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            PowerJoinsServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PromotionsServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }

    /**
     * `app.debug` is true on purpose.
     *
     * A `TypeError` raised inside a Filament schema closure is turned by Livewire
     * into a bare `419 Page Expired` unless debug is on. The symptom is that
     * `Livewire::test(...)->call('save')` leaves `instance()` null, `errors()`
     * empty, nothing written and nothing thrown — so every signature mistake looks
     * like a session problem.
     *
     * `parent::defineEnvironment()` sets `app.key`, which anything rendering a view
     * needs, so it must not be dropped.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', true);
        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    /** The plugin as the test panel attaches it. */
    protected function plugin(): PromotionsPlugin
    {
        return PromotionsPlugin::current();
    }
}
