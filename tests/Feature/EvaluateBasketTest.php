<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesCustomerEligibility;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EvaluateBasket;
use Liberu\Ecommerce\Promotions\Filament\Support\Refusals;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Livewire\Livewire;

beforeEach(function () {
    actAsStaff();
});

function basket(array $overrides = []): array
{
    return array_merge([
        'currency' => 'GBP',
        'currency_exponent' => 2,
        'shipping' => '4.99',
        'customer_ref' => null,
        'codes' => [],
        'lines' => [
            ['product_ref' => 'sku-1', 'quantity' => 2, 'unit_amount' => '10.00'],
            ['product_ref' => 'sku-2', 'quantity' => 1, 'unit_amount' => '5.00'],
        ],
    ], $overrides);
}

function evaluateWith(array $input)
{
    return Livewire::test(EvaluateBasket::class)
        ->callAction(TestAction::make('describeBasket'), $input);
}

it('shows nothing until a basket is described, and writes nothing when it does', function () {
    activate(makeOffer());

    // `assertCountTableRecords` counts the Eloquent query rather than the data
    // source, so a custom-data table is asserted on its records directly. Fixing
    // the assertion, not the table.
    expect(Livewire::test(EvaluateBasket::class)->assertSuccessful()->instance()->getTable()->getRecords())
        ->toHaveCount(0)
        ->and(evaluateWith(basket())->instance()->getTable()->getRecords())
        ->toHaveCount(1);

    // QuoteBasket writes nothing, stores nothing and reserves nothing.
    expect(Offer::query()->firstOrFail()->redemptions_used)->toBe(0)
        ->and(Redemption::query()->count())->toBe(0);
});

it('shows an applied offer with the allocation the domain published', function () {
    activate(makeOffer(['name' => 'Twenty off', 'valueBasisPoints' => 2000]));

    $rows = evaluateWith(basket())->instance()->getTable()->getRecords()->values()->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['outcome'])->toBe('Applied')
        // 20% of £25.00, allocated across the two lines by largest remainder.
        ->and($rows[0]['reduction'])->toBe('5.00')
        ->and($rows[0]['detail'])->toContain('line-1 (sku-1) 4.00')
        ->and($rows[0]['detail'])->toContain('line-2 (sku-2) 1.00');
});

/*
 * Addendum §6. A merchant whose VIP offer has silently applied to nobody for a
 * week must be able to find out why here, and "could not be evaluated" must be a
 * distinct outcome from "did not qualify".
 */
it('names every skipped offer with its refusal reason', function () {
    activate(makeOffer([
        'name' => 'Big spenders only',
        'minimumSubtotal' => Money::fromDecimalString('500.00', 'GBP'),
    ]));

    $rows = evaluateWith(basket())->instance()->getTable()->getRecords()->values()->all();

    expect($rows[0]['outcome'])->toBe('Skipped')
        ->and($rows[0]['offer'])->toBe('Big spenders only')
        ->and($rows[0]['detail'])->toBe(Refusals::label(RefusalReason::MinimumNotMet));
});

it('tells an unresolvable seam apart from an ordinary non-qualification', function () {
    activate(makeOffer([
        'name' => 'VIPs only',
        'customerGroupRefs' => ['vip'],
    ]));
    activate(makeOffer([
        'name' => 'Big spenders only',
        'minimumSubtotal' => Money::fromDecimalString('500.00', 'GBP'),
    ]));

    $rows = collect(evaluateWith(basket(['customer_ref' => 'cus-1']))->instance()->getTable()->getRecords())
        ->keyBy('offer');

    // The seam is unbound, which is the default. The offer that names a group is
    // refused by name; every other offer evaluates normally.
    expect($rows['VIPs only']['outcome'])->toBe('Could not be evaluated')
        ->and($rows['VIPs only']['detail'])->toBe(Refusals::label(RefusalReason::EligibilityUnresolvable))
        ->and($rows['Big spenders only']['outcome'])->toBe('Skipped')
        ->and($rows['Big spenders only']['detail'])->toBe(Refusals::label(RefusalReason::MinimumNotMet));
});

it('applies a segmented offer normally once the host binds the seam', function () {
    app()->bind(ResolvesCustomerEligibility::class, fn (): ResolvesCustomerEligibility => new class() implements ResolvesCustomerEligibility
    {
        public function isCustomerIn(string $customerRef, string $groupRef): bool
        {
            return $groupRef === 'vip';
        }
    });

    activate(makeOffer(['name' => 'VIPs only', 'customerGroupRefs' => ['vip']]));

    $rows = evaluateWith(basket(['customer_ref' => 'cus-1']))->instance()->getTable()->getRecords()->values()->all();

    expect($rows[0]['outcome'])->toBe('Applied');
});

it('shows why a presented code was refused, which only the merchant may see', function () {
    activate(makeOffer(['name' => 'Twenty off']));

    $component = evaluateWith(basket(['codes' => ['NOSUCHCODE']]));

    expect($component->instance()->getTable()->getDescription())
        ->toContain('Refused NOSUCHCODE: '.Refusals::label(RefusalReason::UnknownCode));
});

it('reports free shipping as a real number rather than a zero and a comment', function () {
    activate(makeOffer([
        'name' => 'Free delivery',
        'type' => OfferType::FreeShipping,
        'target' => OfferTarget::Shipping,
        'valueBasisPoints' => null,
    ]));

    $rows = evaluateWith(basket())->instance()->getTable()->getRecords()->values()->all();

    expect($rows[0]['outcome'])->toBe('Applied')
        ->and($rows[0]['reduction'])->toBe('4.99')
        ->and($rows[0]['detail'])->toBe('shipping 4.99');
});

it('shows an exclusive offer blocking the others, by name', function () {
    activate(makeOffer(['name' => 'Exclusive tenner', 'stacking' => StackingMode::Exclusive, 'priority' => 1]));
    activate(makeOffer(['name' => 'Stackable fiver', 'priority' => 2, 'valueBasisPoints' => 500]));

    $rows = collect(evaluateWith(basket())->instance()->getTable()->getRecords())->keyBy('offer');

    expect($rows['Exclusive tenner']['outcome'])->toBe('Applied')
        ->and($rows['Stackable fiver']['outcome'])->toBe('Skipped')
        ->and($rows['Stackable fiver']['detail'])->toBe(Refusals::label(RefusalReason::BlockedByExclusive));
});

/*
 * A custom-data table needs three separate things unwired, and each fails on its
 * own.
 */
it('unwires the three things a custom-data table breaks on', function () {
    activate(makeOffer());

    $table = evaluateWith(basket())->instance()->getTable();
    $record = $table->getRecords()->first();

    expect($record)->toBeArray()
        // 1 and 2: ListRecords attaches a recordAction and a recordUrl closure
        // typed against Model, and these records are arrays.
        ->and($table->getRecordAction($record))->toBeNull()
        ->and($table->getRecordUrl($record))->toBeNull()
        // 3: ViewAction authorizes against a Model, so this table ships none.
        ->and($table->getRecordActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([]);
});

it('re-quotes on every render rather than holding an entitlement', function () {
    activate(makeOffer(['name' => 'Twenty off']));

    $component = evaluateWith(basket());

    expect($component->instance()->getTable()->getRecords()->first()['reduction'])->toBe('5.00');

    // A basket that shrinks loses the entitlement it had. Nothing is cached, so
    // the same component re-quotes against the smaller basket.
    $component->callAction(TestAction::make('describeBasket'), basket([
        'lines' => [['product_ref' => 'sku-1', 'quantity' => 1, 'unit_amount' => '1.00']],
    ]));

    expect($component->instance()->getTable()->getRecords()->first()['reduction'])->toBe('0.20');
});

it('renders every refusal reason the domain publishes', function (RefusalReason $reason) {
    expect(Refusals::label($reason))->toBeString()->not->toBe('');
})->with(array_map(fn (RefusalReason $reason): array => [$reason], RefusalReason::cases()));
