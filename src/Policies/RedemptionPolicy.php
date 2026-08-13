<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Policies;

use Liberu\Ecommerce\Promotions\Models\Redemption;

/**
 * The redemption ledger is read, and a use can be given back.
 *
 * `release` is its own ability rather than `update` or `delete`, because a
 * release is neither: it appends a row to `promotions_redemption_releases`, hands
 * the per-customer slot back and decrements the counter, while the redemption,
 * its lines and its release all survive. The host cannot express this at all — it
 * counts orders, so a cancelled order spends a coupon forever.
 */
final class RedemptionPolicy extends DeniesEveryAbility
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    /** @param  Redemption  $record */
    public function view(mixed $user, mixed $record): bool
    {
        return true;
    }

    /** @param  Redemption  $record */
    public function release(mixed $user, mixed $record): bool
    {
        return true;
    }
}
