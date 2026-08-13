<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Support;

use Liberu\Ecommerce\Promotions\Enums\RefusalReason;

/**
 * Refusal reasons, read out loud — **for the merchant, and only here**.
 *
 * The domain publishes one enum for "why an offer did not apply" and "why a code
 * was refused", because they are the same question from two sides. It is
 * merchant-facing: rendering it to a shopper turns a quote into an oracle for
 * which codes exist, which is wave 7's gift-card rule and a security decision
 * rather than a UX one. This surface is the merchant's, so this is the one place
 * in the fleet these are rendered.
 *
 * `EligibilityUnresolvable` is the reason this list exists. A merchant whose VIP
 * offer has silently applied to nobody for a week must be able to tell "the seam
 * that answers for this group is not bound" from "nobody qualified" without
 * reading logs — so its wording says what to do about it, and it is styled
 * differently from an ordinary non-qualification.
 */
final class Refusals
{
    public static function label(RefusalReason $reason): string
    {
        return match ($reason) {
            RefusalReason::UnknownCode => 'No such code',
            RefusalReason::CodeNotPresented => 'Reachable only by a code, and none was presented',
            RefusalReason::NotYetStarted => 'Has not started yet',
            RefusalReason::Ended => 'Has ended',
            RefusalReason::Exhausted => 'Total redemption limit is spent',
            RefusalReason::CustomerLimitReached => 'This shopper has already had it as often as it allows',
            RefusalReason::CustomerNotEligible => 'This shopper is not in a group the offer names',
            RefusalReason::EligibilityUnresolvable => 'Could not be evaluated: the offer names a group or collection and the seam that answers for it is not bound',
            RefusalReason::MinimumNotMet => 'The basket is below the minimum',
            RefusalReason::NoQualifyingLines => 'Nothing in the basket is what it targets',
            RefusalReason::NothingToReduce => 'The targets matched, but the reduction came to nothing',
            RefusalReason::BlockedByExclusive => 'An exclusive offer applied, so this one may not',
            RefusalReason::CurrencyMismatch => 'Denominated in a different currency from the basket',
        };
    }

    /**
     * Whether a merchant needs to do something about this, as opposed to it being
     * an ordinary non-qualification.
     *
     * An offer that could not be evaluated at all is a broken deployment, not a
     * basket that missed a minimum. Collapsing the two is precisely the failure
     * mode the domain's separate reason exists to prevent.
     */
    public static function needsAttention(RefusalReason $reason): bool
    {
        return $reason === RefusalReason::EligibilityUnresolvable;
    }
}
