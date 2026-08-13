<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Liberu\Ecommerce\Promotions\Actions\ClaimRedemption;
use Liberu\Ecommerce\Promotions\Actions\CreateOffer;
use Liberu\Ecommerce\Promotions\Actions\DecideOfferStatus;
use Liberu\Ecommerce\Promotions\Data\AppliedOffer;
use Liberu\Ecommerce\Promotions\Data\LineAllocation;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Filament\Tests\TestCase;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\PackageTestbench\TestUser;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');

/**
 * Fixtures go through the domain's own actions rather than through Eloquent.
 *
 * An offer written straight into the table has no first revision for a redemption
 * to point at and no decision saying it exists, so a test built that way passes
 * against a shape the application can never produce.
 */
function makeOffer(array $overrides = [], string $tenant = TestCase::TENANT): Offer
{
    $terms = new OfferTerms(...array_merge([
        'name' => 'Twenty off',
        'type' => OfferType::Percentage,
        'target' => OfferTarget::Order,
        'stacking' => StackingMode::Stackable,
        'valueBasisPoints' => 2000,
    ], $overrides));

    return app(CreateOffer::class)($tenant, $terms, 'staff-fixture');
}

function activate(Offer $offer, string $tenant = TestCase::TENANT): Offer
{
    app(DecideOfferStatus::class)(
        $tenant,
        $offer->id,
        OfferStatus::Active,
        OfferStatusReason::MerchantActivated,
        'staff-fixture',
        null,
        CarbonImmutable::now(),
    );

    return $offer->refresh();
}

function actAsStaff(): TestUser
{
    $user = TestUser::factory()->create();

    test()->actingAs($user);

    return $user;
}

/** A redemption claimed exactly as the domain claims one, from an applied offer. */
function claim(Offer $offer, string $orderRef = 'ord_not_a_real_order', ?string $customerRef = null): Redemption
{
    $applied = new AppliedOffer(
        offerId: $offer->id,
        offerName: $offer->name,
        type: $offer->type,
        stacking: $offer->stacking,
        priority: $offer->priority,
        offerRevisionId: (int) $offer->current_revision_id,
        lines: [
            new LineAllocation('line-1', 'sku-1', 400),
            new LineAllocation('line-2', 'sku-2', 100),
        ],
        shippingReductionMinor: 0,
    );

    return app(ClaimRedemption::class)(
        TestCase::TENANT,
        $applied,
        $orderRef,
        'GBP',
        2,
        $customerRef,
    );
}
