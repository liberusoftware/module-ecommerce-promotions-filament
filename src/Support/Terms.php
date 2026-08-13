<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Support;

use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * Reading an offer's terms out loud.
 *
 * Every amount comes from a `Money`, whose `decimal()` is string arithmetic over
 * integer minor units. Nothing here reconstructs an amount from a float, and
 * nothing here divides basis points by anything before rendering them — a rate is
 * shown from the integer it is stored as.
 */
final class Terms
{
    /** What the offer takes off, in one phrase. */
    public static function describe(Offer $offer): string
    {
        $terms = $offer->terms();

        return match ($terms->type) {
            OfferType::Percentage => self::rate((int) $terms->valueBasisPoints).' off',
            OfferType::FixedAmount => $terms->valueAmount instanceof Money
                ? self::money($terms->valueAmount).' off'
                : '—',
            OfferType::FreeShipping => 'Free shipping',
            OfferType::BuyXGetY => sprintf(
                'Buy %d, get %d at %s off',
                (int) $terms->buyQuantity,
                (int) $terms->getQuantity,
                self::rate((int) $terms->valueBasisPoints),
            ),
        };
    }

    /** Where the reduction lands, which decides the allocation and not just the arithmetic. */
    public static function describeTarget(Offer $offer): string
    {
        return match ($offer->target) {
            OfferTarget::Order => 'Spread across every line',
            OfferTarget::Shipping => 'Off the shipping charge',
            OfferTarget::Product => self::countOf($offer->product_refs ?? [], 'named product'),
            OfferTarget::Collection => self::countOf($offer->collection_refs ?? [], 'named collection'),
        };
    }

    /**
     * The cached counter, read as it is stored.
     *
     * It is *not* recomputed here. `RecomputeRedemptionsUsed` publishes the check
     * and the ledger-integrity widget surfaces it; a column that quietly showed
     * the recomputed number instead would hide the drift it exists to reveal.
     */
    public static function describeUsage(Offer $offer): string
    {
        if ($offer->max_redemptions === null) {
            return (string) $offer->redemptions_used;
        }

        return $offer->redemptions_used.' of '.$offer->max_redemptions;
    }

    /**
     * A rate from its basis points: 2000 is 20%, 1250 is 12.5%, 3333 is 33.33%.
     *
     * Integer arithmetic, for the same reason money is. A rate divided into a
     * float to be printed is a rate that prints 33.329999999999998% on some
     * machine somewhere.
     */
    public static function rate(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = rtrim(str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? $whole.'%' : $whole.'.'.$fraction.'%';
    }

    public static function money(Money $money): string
    {
        return $money->currency.' '.$money->decimal();
    }

    /** @param array<int, mixed> $refs */
    private static function countOf(array $refs, string $noun): string
    {
        $count = count($refs);

        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
