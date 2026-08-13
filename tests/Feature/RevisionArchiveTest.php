<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EditOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers\RevisionsRelationManager;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

it('shows every revision, newest first, from the domain query', function () {
    $offer = makeOffer(['name' => 'First name']);

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])
        ->fillForm(['name' => 'Second name'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $offer->refresh(),
        'pageClass' => EditOffer::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords(OfferRevision::query()->orderByDesc('revision_number')->get(), inOrder: true);
});

it('opens an archived set of terms without offering to change it', function () {
    $offer = makeOffer([
        'name' => 'Five off',
        'type' => OfferType::FixedAmount,
        'valueBasisPoints' => null,
        'valueAmount' => Money::fromDecimalString('5.00', 'GBP'),
    ]);

    $revision = OfferRevision::query()->firstOrFail();

    Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $offer,
        'pageClass' => EditOffer::class,
    ])
        ->mountAction(TestAction::make('terms')->table($revision))
        ->assertSuccessful();
});

it('renders an archived money value from the shape the domain published', function () {
    $offer = makeOffer([
        'name' => 'Five off',
        'type' => OfferType::FixedAmount,
        'valueBasisPoints' => null,
        'valueAmount' => Money::fromDecimalString('19.99', 'GBP'),
    ]);

    $archived = OfferRevision::query()->firstOrFail()->terms['value_amount'];

    expect($archived['minor'])->toBe(1999)
        ->and($archived['decimal'])->toBe('19.99')
        // The published API shape hands a consumer a string, because a JSON
        // number puts the value back through a float on the way out.
        ->and($archived['decimal'])->toBeString()
        ->and($offer->terms()->valueAmount?->decimal())->toBe('19.99');
});

it('gives the archive no way to write to itself, and no way to revert', function () {
    $offer = makeOffer();

    $table = Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $offer,
        'pageClass' => EditOffer::class,
    ])->instance()->getTable();

    $actionNames = array_map(fn ($action): string => $action->getName(), $table->getRecordActions());

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([])
        // Reverting is authoring the old terms again — a new revision with a new
        // actor and a new time — and it goes through the offer form.
        ->and($actionNames)->toBe(['terms'])
        ->and($table->getRecordUrl(OfferRevision::query()->firstOrFail()))->toBeNull();
});
