<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Policies;

use Liberu\Ecommerce\Promotions\Models\Code;

/**
 * A code is issued and then read. It is never edited and never deleted.
 *
 * Editing a code would silently invalidate whatever a shopper is holding, and
 * deleting one would erase the row a redemption points at. Withdrawing a code is
 * expressed by ending or pausing its offer, which is a recorded decision.
 *
 * `associate` and `dissociate` matter here specifically: codes hang off the offer
 * as a `hasMany`, where both abilities are live and default open.
 */
final class CodePolicy extends DeniesEveryAbility
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    /** @param  Code  $record */
    public function view(mixed $user, mixed $record): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return true;
    }
}
