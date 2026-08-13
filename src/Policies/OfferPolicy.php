<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Policies;

use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * An offer is authored and revised here, and never deleted.
 *
 * `delete` stays false because ending an offer is a *decision* — it is recorded
 * in `promotions_offer_status_decisions` with an actor, a time and a reason, and
 * the redemptions already claimed under it must survive. A deleted offer is a
 * missing row in a merchant's reconciliation.
 *
 * `update` publishes term revision, which goes through `ReviseOfferTerms`.
 * `decideStatus` is separate from it on purpose: changing what an offer does next
 * and deciding whether it runs at all are different acts, and a panel may
 * reasonably let a junior merchant pause a runaway sale without letting them
 * rewrite its terms.
 */
final class OfferPolicy extends DeniesEveryAbility
{
    /** @param  Offer  $record */
    public function view(mixed $user, mixed $record): bool
    {
        return true;
    }

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return true;
    }

    /** @param  Offer  $record */
    public function update(mixed $user, mixed $record): bool
    {
        return true;
    }

    /** @param  Offer  $record */
    public function decideStatus(mixed $user, mixed $record): bool
    {
        return true;
    }
}
