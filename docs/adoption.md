# Adopting this package

## 1. The domain package is not on Packagist

Composer honours `repositories` **only from the root manifest**, so the entry this
package carries works for its own CI and does nothing for you. Your application
must add the same one:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-promotions" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-promotions-filament" }
]
```

Then:

```
composer require liberusoftware/ecommerce-promotions-filament
```

## 2. Nothing boots on install

This package ships no `extra.laravel.providers`. Composer installing it registers
nothing. Enablement is your explicit decision:

```
MODULES_ENABLED=ecommerce-promotions,ecommerce-promotions-filament
```

The host's `ModuleManagerServiceProvider` globs `config('modules.paths')` for
`*/module.json` and registers only the modules named there. Enable the **domain**
module too — it owns the tables, and this package has none.

## 3. Attach the plugin to the panels that should have it

Enabling the module boots a provider that registers nothing. The panel decides:

```php
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->plugin(
            PromotionsPlugin::make()
                ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey())
                ->actorUsing(fn (): ?string => Auth::id() === null ? null : (string) Auth::id())
        );
}
```

A support panel that should see the ledger but not author offers simply does not
attach it — or attaches it and overrides the policies, which is the supported way
to narrow what it publishes.

### The tenant

Every domain action takes a tenant id and every read is scoped by one. There is no
safe default, so the plugin **throws** when it cannot resolve one. On a panel using
Filament tenancy you may omit `tenantUsing()` and it falls back to
`Filament::getTenant()`.

Do not derive the tenant from anything a request carries. A tenant that arrives in
a query string or a body parameter lets one merchant's session read another's
offers.

### The actor

`actorUsing()` supplies who is acting, for the status decision log and the revision
archive. It defaults to `Filament::auth()->id()`. A null actor is legitimate — a
console-driven change — so this one defaults rather than throwing.

## 4. What this package does not do, and what you must supply

- **It creates no tables.** `liberusoftware/ecommerce-promotions` owns all seven,
  all `promotions_`-prefixed. Run its migrations.
- **It binds no seams.** `ResolvesCustomerEligibility` and `ResolvesProductGrouping`
  are unbound by default. An offer that names a customer group or targets a
  collection with the relevant seam unbound **does not apply**, and the basket
  evaluation page shows it as *Could not be evaluated*. Every other offer evaluates
  normally. Bind them from your own Customers and Catalog modules when you have
  them.
- **It deletes nothing from the host.** The host's `discounts` and `coupons` tables
  are not adopted, migrated or read. `Discount` reaches no order total and never
  did; `Coupon` does, through `CouponService`. Migrating live coupons is a host
  concern and is not attempted here — see `docs/runbook.md`.
- **It surfaces no erasure.** `RedactCustomerFromRedemptions` is published by the
  domain and is not in this panel; see `docs/runbook.md` for why and how to call it.

## 5. Policies

Attaching the plugin registers a policy for each of the five models this panel
routes to, every unpublished ability forced `false` by name. If your application
already registers a policy for any of them, the plugin's registration will replace
it — register yours **after** the panel boots, or subclass the one here so the
denials survive.

`RedemptionLine` and `RedemptionRelease` carry no policy: they are never a
resource or relation-manager record, so no gate is ever asked about them. If you
surface either, write a policy first.

## 6. Versions

- PHP `^8.5`, Laravel `^13.0`, Filament `^5.6`.
- `--prefer-lowest` resolves Filament to 5.6.5. The suite is green on both legs;
  where a Filament test helper behaves differently at the low end, the assertion is
  fixed rather than the constraint narrowed.
