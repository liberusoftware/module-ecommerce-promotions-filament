<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Registers nothing.
 *
 * That is the point rather than an omission. Everything this package contributes
 * arrives through {@see PromotionsPlugin}, which the application attaches to the
 * panels it chooses — so a host that wants promotions in a merchant panel and not
 * in a support panel gets exactly that, and a host that enables the module without
 * attaching the plugin gets no navigation, no routes and no policies.
 *
 * The provider exists because `module.json` must declare one that boots (the
 * boundary suite asserts it), and because the module manager's contract is
 * "enablement boots the provider". It is deliberately empty: this package ships no
 * migrations, no config, no translations and no views.
 */
class PromotionsFilamentServiceProvider extends ServiceProvider
{
    //
}
