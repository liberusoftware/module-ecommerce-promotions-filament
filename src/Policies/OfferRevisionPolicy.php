<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Policies;

use Liberu\Ecommerce\Promotions\Models\OfferRevision;

/**
 * The revision archive is append-only and written only by the domain.
 *
 * Nothing in a panel may create, edit or remove a revision: a redemption names
 * the revision it was evaluated under, and that is the whole mechanism by which
 * "an edit changes the future, not the past" is provable rather than promised.
 */
final class OfferRevisionPolicy extends DeniesEveryAbility
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    /** @param  OfferRevision  $record */
    public function view(mixed $user, mixed $record): bool
    {
        return true;
    }
}
