<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Filament\Widgets\LedgerIntegrity;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

/**
 * The two cached values, checked against the tables they cache — on the page,
 * where a merchant sees them, and not only in a test.
 */
function integrityStats(): array
{
    $stats = [];

    foreach (Livewire::test(LedgerIntegrity::class)->instance()->getStats() as $stat) {
        $stats[(string) $stat->getLabel()] = (string) $stat->getValue();
    }

    return $stats;
}

it('reports no drift while the caches agree with their tables', function () {
    $offer = activate(makeOffer());
    claim($offer);

    expect(integrityStats())->toBe([
        'Offers' => '1',
        'Counter drift' => '0',
        'Status drift' => '0',
    ]);
});

it('notices a counter that has drifted from the ledger', function () {
    $offer = activate(makeOffer());
    claim($offer);

    // Model events do not fire for query()->update(), which is exactly how a
    // cached counter drifts in the first place.
    Offer::query()->whereKey($offer->id)->update(['redemptions_used' => 7]);

    expect(integrityStats()['Counter drift'])->toBe('1')
        ->and(integrityStats()['Status drift'])->toBe('0');
});

it('notices a status that no longer matches its newest decision', function () {
    $offer = activate(makeOffer());

    Offer::query()->whereKey($offer->id)->update(['status' => OfferStatus::Paused->value]);

    expect(integrityStats()['Status drift'])->toBe('1')
        ->and(integrityStats()['Counter drift'])->toBe('0');
});

it('counts only this merchant', function () {
    activate(makeOffer(['name' => 'Mine']));
    makeOffer(['name' => 'Somebody else\'s'], 'merchant-2');

    expect(integrityStats()['Offers'])->toBe('1');
});
