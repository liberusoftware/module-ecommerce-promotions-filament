<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Promotions\Filament\PromotionsFilamentServiceProvider;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\OfferResource;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\ListOffers;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\Pages\ListRedemptions;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\RedemptionResource;
use Liberu\Ecommerce\Promotions\Filament\Tests\TestCase;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\PackageTestbench\PackageRoot;
use Livewire\Livewire;

it('ships a service provider that registers nothing', function () {
    $provider = new ReflectionClass(PromotionsFilamentServiceProvider::class);

    // Everything this package contributes arrives through the plugin, which the
    // application attaches to the panels it chooses. A host that enables the
    // module without attaching the plugin gets no navigation, no routes and no
    // policies.
    $declared = array_filter(
        $provider->getMethods(),
        fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PromotionsFilamentServiceProvider::class,
    );

    expect($declared)->toBe([])
        ->and(app()->getProvider(PromotionsFilamentServiceProvider::class))
        ->not->toBeNull();
});

it('contributes its resources only through the panel plugin', function () {
    $resources = Filament::getCurrentOrDefaultPanel()?->getResources() ?? [];

    expect(array_values($resources))->toBe([OfferResource::class, RedemptionResource::class]);
});

it('registers a policy for every model it routes to, from the plugin', function () {
    // Registered in the plugin rather than the provider, so a host that never
    // attaches it never has these gates answered on its behalf.
    expect(Gate::getPolicyFor(Offer::class))->not->toBeNull();
});

it('declares in its manifest exactly the plugin classes that exist', function () {
    $manifest = PackageRoot::manifest(PackageRoot::locate((string) getcwd())) ?? [];

    expect($manifest['category'])->toBe('presentation')
        ->and($manifest['presentation']['filament'])->not->toBeEmpty();

    foreach ($manifest['presentation']['filament'] as $plugins) {
        foreach ($plugins as $plugin) {
            expect(class_exists($plugin))->toBeTrue()
                ->and(is_a($plugin, Plugin::class, true))->toBeTrue();
        }
    }
});

it('refuses to guess a tenant when the panel cannot name one', function () {
    $plugin = PromotionsPlugin::make();

    expect(fn (): string => $plugin->tenantId())
        // No safe default: a panel that cannot say which merchant it is looking
        // at would otherwise list every merchant's offers.
        ->toThrow(RuntimeException::class);
});

it('scopes every read to the merchant the panel names', function () {
    actAsStaff();

    $mine = makeOffer(['name' => 'Mine']);
    $theirs = makeOffer(['name' => 'Theirs'], 'merchant-2');
    claim($mine);

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(OfferResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())
        ->toBe([TestCase::TENANT])
        ->and(RedemptionResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())
        ->toBe([TestCase::TENANT]);
});

it('cannot route to another merchant record even by id', function () {
    actAsStaff();

    $theirs = makeOffer(['name' => 'Theirs'], 'merchant-2');

    expect(fn () => OfferResource::getEloquentQuery()->findOrFail($theirs->id))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the ledger and the offers list to an authenticated member of staff', function () {
    actAsStaff();

    Livewire::test(ListOffers::class)->assertSuccessful();
    Livewire::test(ListRedemptions::class)->assertSuccessful();
});
