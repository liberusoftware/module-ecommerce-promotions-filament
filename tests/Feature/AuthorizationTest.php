<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Promotions\Filament\Policies\CodePolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\DeniesEveryAbility;
use Liberu\Ecommerce\Promotions\Filament\Policies\OfferPolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\OfferRevisionPolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\OfferStatusDecisionPolicy;
use Liberu\Ecommerce\Promotions\Filament\Policies\RedemptionPolicy;
use Liberu\Ecommerce\Promotions\Models\Code;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;
use Liberu\Ecommerce\Promotions\Models\Redemption;

/*
 * Three separate defaults are permissive, and each one has shipped as a hole in
 * this programme:
 *
 *   - a model with no policy: Laravel's unanswered gate allows;
 *   - a policy missing the method asked about: Filament returns *allow*;
 *   - `associate` and `dissociate` on a hasMany relation manager: open.
 *
 * The host ships `DiscountResource` in the admin panel with no policy at all,
 * which is the sixth instance of the first case in that repository.
 *
 * Published abilities are named per policy. Every other ability Filament's
 * resource and relation-manager authorization passes to the gate must be false,
 * and it is asserted by name rather than by absence — an ability that is false
 * because nobody wrote the method is false by accident.
 */

/** @return array<string, array{class-string, list<string>}> */
function policies(): array
{
    return [
        // resource                       published abilities
        OfferPolicy::class => ['viewAny', 'view', 'create', 'update'],
        RedemptionPolicy::class => ['viewAny', 'view'],
        // relation managers
        CodePolicy::class => ['viewAny', 'view', 'create'],
        OfferRevisionPolicy::class => ['viewAny', 'view'],
        OfferStatusDecisionPolicy::class => ['viewAny', 'view'],
    ];
}

it('forces every unpublished ability false, by name', function (string $policy, array $published) {
    $instance = new $policy();
    $user = actAsStaff();

    foreach (DeniesEveryAbility::ABILITIES as $ability) {
        expect(method_exists($instance, $ability))
            ->toBeTrue("[{$policy}] does not answer [{$ability}] by name.");

        $answer = in_array($ability, DeniesEveryAbility::RECORDLESS_ABILITIES, true)
            ? $instance->{$ability}($user)
            : $instance->{$ability}($user, null);

        expect($answer)->toBe(
            in_array($ability, $published, true),
            "[{$policy}] answers [{$ability}] with the wrong verdict.",
        );
    }
})->with(fn (): array => array_map(
    // One level of nesting per row, because a flat two-element array of strings
    // is PHP's callable-array syntax and Pest calls it instead of iterating it.
    fn (string $policy, array $published): array => [$policy, $published],
    array_keys(policies()),
    array_values(policies()),
));

it('answers the two custom abilities the panel actually asks about', function () {
    $user = actAsStaff();

    expect((new OfferPolicy())->decideStatus($user, null))->toBeTrue()
        ->and((new RedemptionPolicy())->release($user, null))->toBeTrue();
});

it('registers a policy for every model the panel routes to', function (string $model, string $policy) {
    actAsStaff();

    expect(Gate::getPolicyFor($model))->toBeInstanceOf($policy);
})->with([
    [Offer::class, OfferPolicy::class],
    [Code::class, CodePolicy::class],
    [OfferRevision::class, OfferRevisionPolicy::class],
    [OfferStatusDecision::class, OfferStatusDecisionPolicy::class],
    [Redemption::class, RedemptionPolicy::class],
]);

it('denies through the gate, not merely on the policy object', function () {
    actAsStaff();
    $offer = makeOffer();

    expect(Gate::allows('view', $offer))->toBeTrue()
        ->and(Gate::allows('update', $offer))->toBeTrue()
        ->and(Gate::allows('delete', $offer))->toBeFalse()
        ->and(Gate::allows('forceDelete', $offer))->toBeFalse()
        ->and(Gate::allows('replicate', $offer))->toBeFalse()
        ->and(Gate::allows('reorder', Offer::class))->toBeFalse()
        // Live on a hasMany relation manager, and open by default.
        ->and(Gate::allows('associate', Code::class))->toBeFalse()
        ->and(Gate::allows('attach', Code::class))->toBeFalse();
});

it('names no policy method after an ability it does not mean to answer', function () {
    // A subclass method silently wins over a trait's, so the deny-everything base
    // is a class and the published overrides are visible. Nothing may answer an
    // ability that is not in the gate's vocabulary.
    foreach (array_keys(policies()) as $policy) {
        $extra = array_diff(
            array_map(
                fn (ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            DeniesEveryAbility::ABILITIES,
            ['decideStatus', 'release'],
        );

        expect($extra)->toBe([], "[{$policy}] declares a method that is not an ability: ".implode(', ', $extra));
    }
});
