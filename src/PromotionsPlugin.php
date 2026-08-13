<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Promotions\Filament\Policies\CodePolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\OfferPolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\OfferRevisionPolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\OfferStatusDecisionPolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\RedemptionPolicy;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\OfferResource;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\RedemptionResource;
use Liberu\Ecommerce\Promotions\Models\Code;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use RuntimeException;

/**
 * The one thing an application attaches, per panel.
 *
 * Nothing in this package registers globally. The service provider is empty and
 * the panel decides: `->plugin(PromotionsPlugin::make()->tenantUsing(...))`.
 *
 * It carries two resolvers rather than an interface with one implementation,
 * because both answers are properties of the *panel*, not of the domain:
 *
 * - **the tenant.** Every domain action takes a tenant id and every read is
 *   scoped by one. There is no safe default, so an unresolvable tenant throws
 *   rather than falling back — a panel that cannot say which merchant it is
 *   looking at would otherwise list every merchant's offers.
 * - **the actor.** The status decision log and the revision archive both record
 *   who acted. A null actor is legitimate (a console-driven change), so this one
 *   defaults rather than throwing.
 */
final class PromotionsPlugin implements Plugin
{
    public const ID = 'ecommerce-promotions';

    private ?Closure $tenantResolver = null;

    private ?Closure $actorResolver = null;

    public static function make(): self
    {
        return new self();
    }

    /** The instance attached to the panel currently being rendered. */
    public static function current(): self
    {
        $plugin = Filament::getCurrentOrDefaultPanel()?->getPlugin(self::ID);

        if (! $plugin instanceof self) {
            throw new RuntimeException('The promotions plugin is not attached to this panel.');
        }

        return $plugin;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            OfferResource::class,
            RedemptionResource::class,
        ]);

        // Registered here rather than in the service provider so that "nothing
        // registers globally" stays true: a host that never attaches the plugin
        // never has these gates answered on its behalf.
        //
        // A model with no policy is exposed, not safe, and Filament's
        // `get_authorization_response()` returns *allow* when a present policy
        // lacks the method asked about. Every one of these forces the unpublished
        // abilities false by name; see the Policies namespace.
        Gate::policy(Offer::class, OfferPolicy::class);
        Gate::policy(Code::class, CodePolicy::class);
        Gate::policy(OfferRevision::class, OfferRevisionPolicy::class);
        Gate::policy(OfferStatusDecision::class, OfferStatusDecisionPolicy::class);
        Gate::policy(Redemption::class, RedemptionPolicy::class);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /** How this panel answers "which merchant am I looking at?". */
    public function tenantUsing(Closure $resolver): self
    {
        $this->tenantResolver = $resolver;

        return $this;
    }

    /** How this panel answers "who is acting?", for the append-only logs. */
    public function actorUsing(Closure $resolver): self
    {
        $this->actorResolver = $resolver;

        return $this;
    }

    public function tenantId(): string
    {
        $tenant = $this->tenantResolver !== null
            ? ($this->tenantResolver)()
            : Filament::getTenant()?->getKey();

        if (! is_string($tenant) && ! is_int($tenant)) {
            throw new RuntimeException(
                'The promotions plugin could not resolve a tenant for this panel. '
                .'Attach it with ->tenantUsing(fn () => $id) when the panel has no Filament tenant.'
            );
        }

        $tenant = (string) $tenant;

        if (trim($tenant) === '') {
            throw new RuntimeException('The promotions plugin resolved an empty tenant for this panel.');
        }

        return $tenant;
    }

    public function actorRef(): ?string
    {
        $actor = $this->actorResolver !== null
            ? ($this->actorResolver)()
            : Filament::auth()->id();

        if (is_string($actor) || is_int($actor)) {
            return (string) $actor;
        }

        return null;
    }
}
