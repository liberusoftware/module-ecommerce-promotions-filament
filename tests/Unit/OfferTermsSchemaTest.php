<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Schemas\OfferTermsSchema;
use Liberu\Ecommerce\Promotions\Filament\Support\Terms;

it('turns form state into terms without touching a float', function () {
    $terms = OfferTermsSchema::toTerms([
        'name' => 'Nineteen ninety-nine off',
        'type' => OfferType::FixedAmount->value,
        'target' => OfferTarget::Order->value,
        'stacking' => StackingMode::Stackable->value,
        'currency' => 'gbp',
        'currency_exponent' => 2,
        // (int) (19.99 * 100) is 1998. The constant matters: (int) (4.99 * 100)
        // is 499, so a test written with that value fails for the opposite
        // reason to the one it is teaching.
        'value_amount' => '19.99',
        'minimum_subtotal' => '50.00',
    ]);

    expect($terms->valueAmount?->minor)->toBe(1999)
        ->and($terms->valueAmount?->currency)->toBe('GBP')
        ->and($terms->minimumSubtotal?->minor)->toBe(5000)
        ->and($terms->valueBasisPoints)->toBeNull();
});

it('nulls the fields the chosen type has no room for', function () {
    $terms = OfferTermsSchema::toTerms([
        'name' => 'Percentage',
        'type' => OfferType::Percentage->value,
        'target' => OfferTarget::Order->value,
        'stacking' => StackingMode::Stackable->value,
        'value_basis_points' => 2000,
        // Left over from a merchant who changed type after typing them. The
        // domain refuses a rate and an amount together, and refuses a buy
        // quantity on a percentage offer.
        'value_amount' => '5.00',
        'currency' => 'GBP',
        'buy_quantity' => 2,
        'get_quantity' => 1,
        'product_refs' => ['sku-1'],
        'collection_refs' => ['col-1'],
    ]);

    expect($terms->valueAmount)->toBeNull()
        ->and($terms->buyQuantity)->toBeNull()
        ->and($terms->getQuantity)->toBeNull()
        ->and($terms->productRefs)->toBe([])
        ->and($terms->collectionRefs)->toBe([]);
});

it('keeps a currency with no exponent of two', function () {
    $terms = OfferTermsSchema::toTerms([
        'name' => 'Five hundred yen off',
        'type' => OfferType::FixedAmount->value,
        'target' => OfferTarget::Order->value,
        'stacking' => StackingMode::Stackable->value,
        'currency' => 'JPY',
        'currency_exponent' => 0,
        'value_amount' => '500',
    ]);

    expect($terms->valueAmount?->minor)->toBe(500)
        ->and($terms->valueAmount?->exponent)->toBe(0)
        ->and($terms->valueAmount?->decimal())->toBe('500');
});

it('drops blank and duplicate references rather than passing them through', function () {
    $terms = OfferTermsSchema::toTerms([
        'name' => 'Products',
        'type' => OfferType::Percentage->value,
        'target' => OfferTarget::Product->value,
        'stacking' => StackingMode::Stackable->value,
        'value_basis_points' => 1000,
        'product_refs' => [' sku-1 ', 'sku-1', '', 'sku-2'],
    ]);

    expect($terms->productRefs)->toBe(['sku-1', 'sku-2']);
});

it('round-trips an offer through the form and back to the same terms', function () {
    $offer = makeOffer([
        'name' => 'Buy two get one',
        'type' => OfferType::BuyXGetY,
        'valueBasisPoints' => 10000,
        'buyQuantity' => 2,
        'getQuantity' => 1,
        'minimumSubtotal' => Money::fromDecimalString('10.00', 'GBP'),
        'customerGroupRefs' => ['vip'],
        'maxRedemptions' => 100,
        'maxRedemptionsPerCustomer' => 1,
    ]);

    $again = OfferTermsSchema::toTerms(OfferTermsSchema::fromOffer($offer));

    expect($again->toArray())->toBe($offer->terms()->toArray());
});

it('reads a rate out of its basis points with integer arithmetic', function (int $basisPoints, string $expected) {
    expect(Terms::rate($basisPoints))->toBe($expected);
})->with([
    [2000, '20%'],
    [1250, '12.5%'],
    [3333, '33.33%'],
    [10000, '100%'],
    [1, '0.01%'],
]);

it('describes each kind of offer the way a merchant reads it', function () {
    expect(Terms::describe(makeOffer(['name' => 'A', 'valueBasisPoints' => 2000])))->toBe('20% off')
        ->and(Terms::describe(makeOffer([
            'name' => 'B',
            'type' => OfferType::FixedAmount,
            'valueBasisPoints' => null,
            'valueAmount' => Money::fromDecimalString('5.00', 'GBP'),
        ])))->toBe('GBP 5.00 off')
        ->and(Terms::describe(makeOffer([
            'name' => 'C',
            'type' => OfferType::FreeShipping,
            'target' => OfferTarget::Shipping,
            'valueBasisPoints' => null,
        ])))->toBe('Free shipping')
        ->and(Terms::describe(makeOffer([
            'name' => 'D',
            'type' => OfferType::BuyXGetY,
            'valueBasisPoints' => 10000,
            'buyQuantity' => 2,
            'getQuantity' => 1,
        ])))->toBe('Buy 2, get 1 at 100% off');
});

it('reads the cached counter as it is stored, without recomputing it', function () {
    $offer = makeOffer(['maxRedemptions' => 10]);

    expect(Terms::describeUsage($offer))->toBe('0 of 10')
        ->and(Terms::describeUsage(makeOffer(['name' => 'No limit'])))->toBe('0');
});
