<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\Pages\ListRedemptions;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\RedemptionResource;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Models\RedemptionRelease;
use Liberu\Ecommerce\Promotions\Queries\RecomputeRedemptionsUsed;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

it('lists a redemption against an order reference it cannot resolve', function () {
    $redemption = claim(activate(makeOffer()));

    Livewire::test(ListRedemptions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$redemption])
        ->assertTableColumnStateSet('order_ref', 'ord_not_a_real_order', $redemption);
});

it('renders money from the domain Money rather than reconstructing an amount', function () {
    $redemption = claim(activate(makeOffer()));

    expect($redemption->total()->decimal())->toBe('5.00')
        ->and($redemption->total()->currency)->toBe('GBP');

    Livewire::test(ListRedemptions::class)
        ->assertTableColumnStateSet('total', 'GBP 5.00', $redemption);
});

it('gives a use back as its own append-only record, not as a deletion', function () {
    $offer = activate(makeOffer(['maxRedemptions' => 5]));
    $redemption = claim($offer, customerRef: 'cus-1');

    expect($offer->refresh()->redemptions_used)->toBe(1);

    Livewire::test(ListRedemptions::class)
        ->callAction(
            TestAction::make('release')->table($redemption),
            ['reason' => ReleaseReason::OrderCancelled->value, 'note' => 'Shopper cancelled'],
        );

    $redemption->refresh();

    expect(Redemption::query()->count())->toBe(1)
        ->and($redemption->lines()->count())->toBe(2)
        ->and($redemption->release?->reason)->toBe(ReleaseReason::OrderCancelled)
        ->and($redemption->release?->actor_ref)->toBe('staff-1')
        ->and($redemption->release?->note)->toBe('Shopper cancelled')
        // The slot is a constraint marker rather than a fact: releasing hands it
        // back, and the redemption, its lines and its release all survive.
        ->and($redemption->customer_sequence)->toBeNull()
        ->and($offer->refresh()->redemptions_used)->toBe(0);
});

it('hides the release action on a redemption that already has one', function () {
    $redemption = claim(activate(makeOffer()));

    Livewire::test(ListRedemptions::class)
        ->assertActionVisible(TestAction::make('release')->table($redemption));

    RedemptionRelease::query()->create([
        'redemption_id' => $redemption->id,
        'reason' => ReleaseReason::OrderRefunded,
        'occurred_at' => now(),
    ]);

    Livewire::test(ListRedemptions::class)
        ->assertActionHidden(TestAction::make('release')->table($redemption->refresh()));
});

it('keeps the cached counter agreeing with the ledger through a claim and a release', function () {
    $offer = activate(makeOffer());
    $recompute = app(RecomputeRedemptionsUsed::class);

    expect($recompute->agrees($offer->refresh()))->toBeTrue();

    $redemption = claim($offer);

    expect($recompute->agrees($offer->refresh()))->toBeTrue();

    Livewire::test(ListRedemptions::class)
        ->callAction(TestAction::make('release')->table($redemption), ['reason' => ReleaseReason::PaymentFailed->value]);

    expect($recompute->agrees($offer->refresh()))->toBeTrue();
});

it('shows the allocation, the revision and the release on the record', function () {
    $offer = activate(makeOffer());
    $redemption = claim($offer, customerRef: 'cus-1');

    Livewire::test(ListRedemptions::class)
        ->mountAction(TestAction::make('view')->table($redemption))
        ->assertSuccessful();

    expect($redemption->offer_revision_id)->toBe($offer->current_revision_id)
        ->and($redemption->lines->pluck('amount_minor')->all())->toBe([400, 100]);
});

/*
 * A search term and a filter state both persist into the query string. An order
 * reference is the merchant's own identifier and the ledger is unusable without
 * it; a shopper reference is not a thing to leave in browser history.
 */
it('makes the order reference searchable and the customer reference neither searchable nor filterable', function () {
    $table = Livewire::test(ListRedemptions::class)->instance()->getTable();

    $searchable = [];

    foreach ($table->getColumns() as $column) {
        if ($column->isSearchable()) {
            $searchable[] = $column->getName();
        }
    }

    $filters = array_values(array_map(fn ($filter): string => $filter->getName(), $table->getFilters()));

    expect($searchable)->toBe(['order_ref'])
        ->and($filters)->not->toContain('customer_ref')
        ->and($filters)->toBe(['released', 'offer_id']);
});

it('never deletes a redemption, in bulk or otherwise', function () {
    $table = Livewire::test(ListRedemptions::class)->instance()->getTable();

    $actions = array_map(fn ($action): string => $action->getName(), $table->getRecordActions());

    expect($actions)->toBe(['view', 'release'])
        ->and($table->getToolbarActions())->toBe([]);
});

it('names every release reason the domain publishes', function () {
    $options = RedemptionResource::releaseReasons();

    expect(array_keys($options))->toBe(array_map(
        fn (ReleaseReason $reason): string => $reason->value,
        ReleaseReason::cases(),
    ));
});
