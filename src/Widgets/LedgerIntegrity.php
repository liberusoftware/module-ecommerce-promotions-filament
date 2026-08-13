<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Queries\RecomputeOfferStatus;
use Liberu\Ecommerce\Promotions\Queries\RecomputeRedemptionsUsed;

/**
 * The two cached values in this module, checked against the tables they cache.
 *
 * A cached counter nobody can check is a number nobody should trust. Both caches
 * exist for good reasons — `redemptions_used` because a conditional update is the
 * only race-free way to enforce a limit, and `status` because evaluation cannot
 * fold a decision log on every basket — and the domain publishes a recompute for
 * each, with an `agrees()` that says whether the cache still matches.
 *
 * Those recomputes are surfaced **here**, where a merchant sees them, rather than
 * only in a test. A check that runs once in CI proves the code was right when it
 * shipped; a check on the page proves the data is right now. Drift is a bug
 * somewhere, and the merchant is the person who finds out first that a limit is
 * counting wrong.
 *
 * Nothing is recomputed *for display*: the ledger and the offer list show the
 * cached values as they are stored. Showing the recomputed number in their place
 * would hide exactly the drift this widget exists to reveal.
 */
class LedgerIntegrity extends StatsOverviewWidget
{
    protected ?string $heading = 'Ledger integrity';

    protected ?string $description = 'The cached counter and the cached status, each re-derived from the append-only tables behind it.';

    protected int|string|array $columnSpan = 'full';

    /**
     * Public rather than protected, so the check can be asserted directly rather
     * than scraped out of rendered HTML. Widening visibility is the whole change.
     *
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        $counter = App::make(RecomputeRedemptionsUsed::class);
        $status = App::make(RecomputeOfferStatus::class);

        $offers = 0;
        $counterDrift = 0;
        $statusDrift = 0;

        // ponytail: two queries per offer, which is fine for a merchant's own
        // offer list and would not be for a fleet-wide report. If an operator ever
        // needs this across every merchant, it belongs in a scheduled check that
        // publishes a number, not in a page render.
        foreach ($this->offers() as $offer) {
            $offers++;

            if (! $counter->agrees($offer)) {
                $counterDrift++;
            }

            if (! $status->agrees($offer)) {
                $statusDrift++;
            }
        }

        return [
            Stat::make('Offers', (string) $offers)
                ->description('In this merchant'),
            Stat::make('Counter drift', (string) $counterDrift)
                ->description($counterDrift === 0
                    ? 'Every cached count matches its redemptions and releases'
                    : 'Offers whose redemptions_used disagrees with the ledger')
                ->color($counterDrift === 0 ? 'success' : 'danger'),
            Stat::make('Status drift', (string) $statusDrift)
                ->description($statusDrift === 0
                    ? 'Every cached status matches its newest decision'
                    : 'Offers whose status disagrees with the decision log')
                ->color($statusDrift === 0 ? 'success' : 'danger'),
        ];
    }

    /** @return Collection<int, Offer> */
    protected function offers(): Collection
    {
        return Offer::query()
            ->where('tenant_id', PromotionsPlugin::current()->tenantId())
            ->get();
    }
}
