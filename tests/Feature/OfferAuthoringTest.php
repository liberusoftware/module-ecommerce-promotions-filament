<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\CreateOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EditOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Schemas\OfferTermsSchema;
use Liberu\Ecommerce\Promotions\Filament\Tests\TestCase;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

/*
 * The host's DiscountResource::form() returns ->components([//]) — an empty schema
 * over a table whose `title` is NOT NULL, so the Create page renders a form with
 * no fields and the feature is dead at both ends. These are the tests that say it
 * is not dead here.
 */

it('offers a field for every term a merchant sets', function () {
    $fields = [
        'name', 'description', 'type', 'target', 'stacking', 'priority',
        'value_basis_points', 'value_amount', 'buy_quantity', 'get_quantity',
        'currency', 'currency_exponent',
        'product_refs', 'collection_refs', 'customer_group_refs',
        'minimum_subtotal', 'minimum_quantity',
        'starts_at', 'ends_at', 'max_redemptions', 'max_redemptions_per_customer',
    ];

    $schema = Livewire::test(CreateOffer::class)->instance()->getSchema('form');

    $present = array_keys($schema?->getFlatFields(withHidden: true) ?? []);

    expect($present)->toContain(...$fields);
});

it('does not offer status as a field, because a status is a decision', function () {
    $schema = Livewire::test(CreateOffer::class)->instance()->getSchema('form');

    expect(array_keys($schema?->getFlatFields(withHidden: true) ?? []))
        ->not->toContain('status');
});

it('creates an offer through the domain action, with its first revision and decision', function () {
    Livewire::test(CreateOffer::class)
        ->fillForm([
            'name' => 'Twenty off everything',
            'type' => OfferType::Percentage->value,
            'target' => OfferTarget::Order->value,
            'stacking' => StackingMode::Stackable->value,
            'value_basis_points' => 2000,
            'priority' => 5,
            'currency_exponent' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $offer = Offer::query()->firstOrFail();

    expect($offer->tenant_id)->toBe(TestCase::TENANT)
        ->and($offer->status)->toBe(OfferStatus::Draft)
        ->and($offer->value_basis_points)->toBe(2000)
        ->and($offer->priority)->toBe(5)
        ->and($offer->current_revision_id)->not->toBeNull()
        ->and($offer->revisions()->count())->toBe(1)
        ->and($offer->statusDecisions()->count())->toBe(1);
});

it('records the panel actor on the revision it writes', function () {
    TestCase::$actor = 'staff-99';

    Livewire::test(CreateOffer::class)
        ->fillForm([
            'name' => 'Five off',
            'type' => OfferType::FixedAmount->value,
            'target' => OfferTarget::Order->value,
            'stacking' => StackingMode::Exclusive->value,
            'value_amount' => '5.00',
            'currency' => 'gbp',
            'currency_exponent' => 2,
            'priority' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $offer = Offer::query()->firstOrFail();

    expect($offer->value_minor)->toBe(500)
        ->and($offer->currency)->toBe('GBP')
        ->and(OfferRevision::query()->firstOrFail()->actor_ref)->toBe('staff-99');
});

it('parses money as a decimal string and never through a float', function () {
    Livewire::test(CreateOffer::class)
        ->fillForm([
            'name' => 'Nineteen ninety-nine off',
            'type' => OfferType::FixedAmount->value,
            'target' => OfferTarget::Order->value,
            'stacking' => StackingMode::Stackable->value,
            // (int) (19.99 * 100) is 1998, because 19.99 has no exact binary
            // form. This constant is the one that catches it; (int) (4.99 * 100)
            // is 499 and would pass a broken implementation.
            'value_amount' => '19.99',
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'priority' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Offer::query()->firstOrFail()->value_minor)->toBe(1999);
});

it('revises terms rather than overwriting them, keeping the archive', function () {
    $offer = makeOffer(['name' => 'Twenty off']);

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])
        ->fillForm(['name' => 'Twenty-five off', 'value_basis_points' => 2500])
        ->call('save')
        ->assertHasNoFormErrors();

    $offer->refresh();

    expect($offer->name)->toBe('Twenty-five off')
        ->and($offer->value_basis_points)->toBe(2500)
        ->and($offer->revision_number)->toBe(2)
        ->and($offer->revisions()->count())->toBe(2)
        ->and($offer->revisions()->orderBy('revision_number')->first()?->terms['name'])->toBe('Twenty off');
});

it('fills the edit form from the live columns, not from the archive', function () {
    $offer = makeOffer([
        'name' => 'Named products',
        'target' => OfferTarget::Product,
        'productRefs' => ['sku-1', 'sku-2'],
        'minimumQuantity' => 3,
    ]);

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])
        ->assertSchemaStateSet([
            'name' => 'Named products',
            'target' => OfferTarget::Product->value,
            'product_refs' => ['sku-1', 'sku-2'],
            'minimum_quantity' => 3,
        ], 'form');
});

/*
 * The form must not let a merchant save terms the domain will reject.
 * InvalidOfferTerms is a backstop, not a UX.
 */
it('refuses terms the domain would refuse', function (array $data, array $errors) {
    Livewire::test(CreateOffer::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(Offer::query()->count())->toBe(0);
})->with([
    // Each row is a two-element array of arrays, so it is unambiguous. A row of
    // two class-strings would not be: [Foo::class, 'method'] is PHP's callable-array
    // syntax, and Pest calls it rather than iterating it.
    [
        ['name' => '', 'type' => 'percentage', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => 2000],
        ['name'],
    ],
    [
        ['name' => 'No rate', 'type' => 'percentage', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => null],
        ['value_basis_points'],
    ],
    [
        ['name' => 'Rate too high', 'type' => 'percentage', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => 10001],
        ['value_basis_points'],
    ],
    [
        ['name' => 'Nothing off', 'type' => 'fixed_amount', 'target' => 'order', 'stacking' => 'stackable', 'currency' => 'GBP', 'currency_exponent' => 2, 'value_amount' => '0.00'],
        ['value_amount'],
    ],
    [
        ['name' => 'Too precise', 'type' => 'fixed_amount', 'target' => 'order', 'stacking' => 'stackable', 'currency' => 'GBP', 'currency_exponent' => 2, 'value_amount' => '5.005'],
        ['value_amount'],
    ],
    [
        ['name' => 'No currency', 'type' => 'fixed_amount', 'target' => 'order', 'stacking' => 'stackable', 'value_amount' => '5.00'],
        ['currency'],
    ],
    [
        ['name' => 'No products named', 'type' => 'percentage', 'target' => 'product', 'stacking' => 'stackable', 'value_basis_points' => 1000, 'product_refs' => []],
        ['product_refs'],
    ],
    [
        ['name' => 'No collections named', 'type' => 'percentage', 'target' => 'collection', 'stacking' => 'stackable', 'value_basis_points' => 1000, 'collection_refs' => []],
        ['collection_refs'],
    ],
    [
        ['name' => 'No quantities', 'type' => 'buy_x_get_y', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => 10000],
        ['buy_quantity', 'get_quantity'],
    ],
    [
        ['name' => 'Ends before it starts', 'type' => 'percentage', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => 1000, 'starts_at' => '2026-08-10 00:00:00', 'ends_at' => '2026-08-01 00:00:00'],
        ['ends_at'],
    ],
    [
        ['name' => 'Zero minimum quantity', 'type' => 'percentage', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => 1000, 'minimum_quantity' => 0],
        ['minimum_quantity'],
    ],
    [
        ['name' => 'Zero limit', 'type' => 'percentage', 'target' => 'order', 'stacking' => 'stackable', 'value_basis_points' => 1000, 'max_redemptions' => 0],
        ['max_redemptions'],
    ],
]);

it('makes the pairs the domain forbids outright unreachable rather than merely refused', function () {
    // A free-shipping offer targets shipping, and nothing else may. The select
    // offers exactly one option in each direction, so neither half of the pair
    // can be typed.
    expect(array_keys(OfferTermsSchema::targetOptions(OfferType::FreeShipping->value)))
        ->toBe([OfferTarget::Shipping->value])
        ->and(array_keys(OfferTermsSchema::targetOptions(OfferType::Percentage->value)))
        ->not->toContain(OfferTarget::Shipping->value);
});

it('creates a free-shipping offer with no value at all', function () {
    Livewire::test(CreateOffer::class)
        ->fillForm([
            'name' => 'Free delivery',
            'type' => OfferType::FreeShipping->value,
            'target' => OfferTarget::Shipping->value,
            'stacking' => StackingMode::Stackable->value,
            'priority' => 0,
            'currency_exponent' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $offer = Offer::query()->firstOrFail();

    expect($offer->type)->toBe(OfferType::FreeShipping)
        ->and($offer->target)->toBe(OfferTarget::Shipping)
        ->and($offer->value_basis_points)->toBeNull()
        ->and($offer->value_minor)->toBeNull();
});
