# Promotions — Filament panel

[![Tests](https://github.com/liberusoftware/module-ecommerce-promotions-filament/actions/workflows/tests.yml/badge.svg)](https://github.com/liberusoftware/module-ecommerce-promotions-filament/actions/workflows/tests.yml)

The merchant-facing panel over
[`liberusoftware/ecommerce-promotions`](https://github.com/liberusoftware/module-ecommerce-promotions).

It adds **no business rule of its own**. Every write goes through a domain action;
every read comes from a domain query or a tenant-scoped Eloquent query. Nothing
here recomputes a state, a supersession, an allocation or an aggregate the domain
already publishes.

```
composer require liberusoftware/ecommerce-promotions-filament
```

The domain package is not on Packagist. See [`docs/adoption.md`](docs/adoption.md)
for the `repositories` entry your application must add.

## What it gives a merchant

| Surface | What it is |
|---|---|
| **Offers** | Authoring, with a form that expresses every term the domain accepts and refuses every one it rejects |
| **Status decisions** | Who activated, paused, resumed or ended an offer, when, and why |
| **Revisions** | What an offer's terms were at each revision, archived and read-only |
| **Codes** | Ways of reaching an offer. Many per offer, or none |
| **Evaluate a basket** | Every active offer applied — with its allocation — or **skipped by name, with its reason** |
| **Redemptions** | The ledger, with its lines, its revision and its release, and giving a use back |
| **Ledger integrity** | The two cached values, re-derived from the append-only tables behind them |

## Attaching it

Nothing registers globally. The panel decides:

```php
use Filament\Panel;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->plugin(
            PromotionsPlugin::make()
                ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey())
                ->actorUsing(fn (): ?string => (string) Auth::id())
        );
}
```

`tenantUsing()` may be omitted on a panel with Filament tenancy — it falls back to
`Filament::getTenant()`. It **throws** rather than defaulting when no tenant can be
resolved: a panel that cannot say which merchant it is looking at would otherwise
list every merchant's offers.

## Four things this panel will not do

- **Write a status column.** A status is a decision, with an actor, a time and a
  closed-enum reason. `DecideOfferStatus` writes it and the log is read-only here.
- **Edit or delete an append-only row.** Revisions, status decisions, redemptions
  and releases are read in this panel and written only by the domain.
- **Make a code searchable or filterable.** A search term and a filter state both
  persist into the query string. A promo code is a bearer-ish value.
- **Cache an entitlement.** The basket evaluation re-quotes on every render. An
  entitlement is perishable: a basket that shrinks loses the one it had.

## Documentation

- [`docs/domain.md`](docs/domain.md) — what each surface is, and the decisions behind it
- [`docs/adoption.md`](docs/adoption.md) — installing, attaching, and what the host must supply
- [`docs/runbook.md`](docs/runbook.md) — the questions this panel is there to answer

## Licence

MIT. See [LICENSE.md](LICENSE.md).
