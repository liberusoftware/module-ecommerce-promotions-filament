<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Support\Terms;

it('names itself the same as the module, so a panel can find it', function () {
    expect(PromotionsPlugin::make()->getId())->toBe('ecommerce-promotions')
        ->and(PromotionsPlugin::current())->toBeInstanceOf(PromotionsPlugin::class);
});

it('refuses to answer for a panel it is not attached to', function () {
    $bare = Panel::make()->id('unattached');

    expect(fn (): PromotionsPlugin => $bare->getPlugin(PromotionsPlugin::ID))
        ->toThrow(Exception::class);
});

it('boots without contributing anything of its own', function () {
    $plugin = PromotionsPlugin::make();

    // Everything is contributed in register(); boot() exists because the contract
    // demands it and has nothing to do.
    expect($plugin->boot(Panel::make()->id('anything')))->toBeNull();
});

it('resolves the actor the panel names, and tolerates none', function () {
    expect(PromotionsPlugin::current()->actorRef())->toBe('staff-1')
        ->and(PromotionsPlugin::make()->actorUsing(fn (): ?string => null)->actorRef())->toBeNull()
        ->and(PromotionsPlugin::make()->actorUsing(fn (): int => 7)->actorRef())->toBe('7');
});

it('refuses an empty tenant as firmly as a missing one', function () {
    expect(fn (): string => PromotionsPlugin::make()->tenantUsing(fn (): string => '  ')->tenantId())
        ->toThrow(RuntimeException::class)
        ->and(PromotionsPlugin::make()->tenantUsing(fn (): int => 42)->tenantId())
        ->toBe('42');
});

it('says where a targeted offer takes its money from', function () {
    $product = makeOffer([
        'name' => 'Two products',
        'target' => OfferTarget::Product,
        'productRefs' => ['sku-1', 'sku-2'],
    ]);

    $collection = makeOffer([
        'name' => 'One collection',
        'target' => OfferTarget::Collection,
        'collectionRefs' => ['col-1'],
    ]);

    $shipping = makeOffer([
        'name' => 'Free delivery',
        'type' => OfferType::FreeShipping,
        'target' => OfferTarget::Shipping,
        'valueBasisPoints' => null,
    ]);

    expect(Terms::describeTarget($product))->toBe('2 named products')
        ->and(Terms::describeTarget($collection))->toBe('1 named collection')
        ->and(Terms::describeTarget($shipping))->toBe('Off the shipping charge')
        ->and(Terms::describeTarget(makeOffer(['name' => 'Order-wide'])))->toBe('Spread across every line');
});
