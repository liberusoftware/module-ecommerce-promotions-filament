<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EditOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers\CodesRelationManager;
use Liberu\Ecommerce\Promotions\Filament\Tests\TestCase;
use Liberu\Ecommerce\Promotions\Models\Code;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

function codesFor($offer)
{
    return Livewire::test(CodesRelationManager::class, [
        'ownerRecord' => $offer,
        'pageClass' => EditOffer::class,
    ]);
}

it('issues a code through the domain action, normalised to one spelling', function () {
    $offer = makeOffer();

    codesFor($offer)->callAction(TestAction::make('issue')->table(), ['code' => ' summer10 ']);

    $code = Code::query()->firstOrFail();

    expect($code->code)->toBe('SUMMER10')
        ->and($code->offer_id)->toBe($offer->id)
        ->and($code->tenant_id)->toBe(TestCase::TENANT);
});

it('refuses a code the merchant has already issued, without a lookup first', function () {
    $offer = makeOffer();

    codesFor($offer)->callAction(TestAction::make('issue')->table(), ['code' => 'SUMMER10']);
    codesFor($offer)->callAction(TestAction::make('issue')->table(), ['code' => 'summer10']);

    // Caught from the unique index rather than guarded against: a
    // check-then-insert is not a constraint.
    expect(Code::query()->count())->toBe(1);
});

it('lets an offer be reached by many codes, or by none', function () {
    $offer = makeOffer();

    codesFor($offer)->callAction(TestAction::make('issue')->table(), ['code' => 'CAMPAIGN']);
    codesFor($offer)->callAction(TestAction::make('issue')->table(), ['code' => 'PARTNER']);

    expect($offer->codes()->count())->toBe(2)
        ->and(makeOffer(['name' => 'Automatic'])->codes()->count())->toBe(0);
});

/*
 * A search term and a filter state both persist into the query string, into
 * browser history and into every screenshot pasted into a ticket. A promo code is
 * a bearer-ish value: whoever holds it can spend the offer.
 */
it('makes no code searchable and no code filterable', function () {
    $table = codesFor(makeOffer())->instance()->getTable();

    foreach ($table->getColumns() as $column) {
        expect($column->isSearchable())->toBeFalse();
    }

    expect($table->getFilters())->toBe([])
        ->and($table->isSearchable())->toBeFalse();
});

it('never edits and never deletes a code', function () {
    $table = codesFor(makeOffer())->instance()->getTable();

    expect($table->getRecordActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([]);
});
