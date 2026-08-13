<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Policies;

use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;

/**
 * The status decision log is append-only and written only by `DecideOfferStatus`.
 *
 * "Who paused the Black Friday sale, and when" is a question somebody asks at 9am
 * on Black Friday. A log a panel can edit is not an answer to it, so `create` and
 * `update` are false here even though the panel is what triggers a decision: the
 * decision is made through the domain action, which writes the row.
 */
final class OfferStatusDecisionPolicy extends DeniesEveryAbility
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    /** @param  OfferStatusDecision  $record */
    public function view(mixed $user, mixed $record): bool
    {
        return true;
    }
}
