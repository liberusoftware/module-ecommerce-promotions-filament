<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EditOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\ListOffers;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers\StatusDecisionsRelationManager;
use Liberu\Ecommerce\Promotions\Filament\Tests\TestCase;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;
use Liberu\Ecommerce\Promotions\Queries\RecomputeOfferStatus;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

/*
 * "Who paused the Black Friday sale, and when" is a question somebody asks at 9am
 * on Black Friday. The host's answer is `discounts.is_active`, which records
 * neither. These tests are that question, asked of this panel.
 */

it('activates a draft through the domain action, recording who and why', function () {
    TestCase::$actor = 'staff-42';
    $offer = makeOffer();

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])
        ->callAction('activate', ['note' => 'Signed off by finance']);

    $offer->refresh();
    $decision = OfferStatusDecision::query()->latest('id')->firstOrFail();

    expect($offer->status)->toBe(OfferStatus::Active)
        ->and($decision->from_status)->toBe(OfferStatus::Draft)
        ->and($decision->to_status)->toBe(OfferStatus::Active)
        ->and($decision->reason)->toBe(OfferStatusReason::MerchantActivated)
        ->and($decision->actor_ref)->toBe('staff-42')
        ->and($decision->note)->toBe('Signed off by finance')
        ->and($decision->occurred_at)->not->toBeNull();
});

it('tells resuming a pause apart from activating a draft', function () {
    $offer = activate(makeOffer());

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])->callAction('pause');
    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])->callAction('activate');

    $reasons = OfferStatusDecision::query()->orderBy('id')->pluck('reason')->all();

    expect($reasons)->toBe([
        OfferStatusReason::Created,
        OfferStatusReason::MerchantActivated,
        OfferStatusReason::MerchantPaused,
        OfferStatusReason::MerchantResumed,
    ]);
});

it('ends an offer without deleting it, so its redemptions survive', function () {
    $offer = activate(makeOffer());

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])->callAction('end');

    expect($offer->refresh()->status)->toBe(OfferStatus::Ended)
        ->and($offer->exists)->toBeTrue()
        ->and(OfferStatusDecision::query()->latest('id')->firstOrFail()->reason)
        ->toBe(OfferStatusReason::MerchantEnded);
});

it('offers only the transitions that make sense from where the offer is', function () {
    $draft = makeOffer(['name' => 'A draft']);

    Livewire::test(EditOffer::class, ['record' => $draft->getKey()])
        ->assertActionVisible('activate')
        ->assertActionHidden('pause')
        ->assertActionVisible('end');

    $active = activate(makeOffer(['name' => 'An active one']));

    Livewire::test(EditOffer::class, ['record' => $active->getKey()])
        ->assertActionHidden('activate')
        ->assertActionVisible('pause')
        ->assertActionVisible('end');
});

it('leaves the cached status agreeing with the decision log after every change', function () {
    $offer = makeOffer();
    $recompute = app(RecomputeOfferStatus::class);

    foreach (['activate', 'pause', 'activate', 'end'] as $action) {
        Livewire::test(EditOffer::class, ['record' => $offer->getKey()])->callAction($action);

        expect($recompute->agrees($offer->refresh()))->toBeTrue();
    }
});

it('shows the decision log as a read-only first-class surface', function () {
    $offer = activate(makeOffer());

    Livewire::test(StatusDecisionsRelationManager::class, [
        'ownerRecord' => $offer,
        'pageClass' => EditOffer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(OfferStatusDecision::query()->orderByDesc('id')->get())
        ->assertCountTableRecords(2);
});

it('gives the decision log no way to write to itself', function () {
    $offer = activate(makeOffer());

    $table = Livewire::test(StatusDecisionsRelationManager::class, [
        'ownerRecord' => $offer,
        'pageClass' => EditOffer::class,
    ])->instance()->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getRecordActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([])
        // A log row has nothing underneath it to open, and a clickable row would
        // offer an edit page that must never exist.
        ->and($table->getRecordUrl(OfferStatusDecision::query()->firstOrFail()))->toBeNull();
});

it('reaches the status actions from the offers list as well', function () {
    $offer = makeOffer();

    Livewire::test(ListOffers::class)
        ->callTableAction('activate', $offer);

    expect($offer->refresh()->status)->toBe(OfferStatus::Active);
});
