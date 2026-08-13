<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Schemas;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * The form that answers the host's empty schema.
 *
 * `DiscountResource::form()` in the host returns `->components([//])` — no fields
 * at all, over a table whose `title` column is `NOT NULL`. The feature is dead at
 * both ends: nothing reads a `Discount` either. A form that cannot express the
 * terms is worse than no form, so every field of `OfferTerms` a merchant sets is
 * settable here.
 *
 * **The form must not let a merchant save terms the domain will reject.**
 * `InvalidOfferTerms` is a backstop, not a UX: every rule `OfferTerms::validate()`
 * enforces has a counterpart here, either as a validation rule or as a field that
 * is not shown for a type that forbids it. The combinations the domain rejects
 * outright — a rate on a fixed-amount offer, a buy quantity on a percentage offer,
 * a non-shipping target on free shipping — are unreachable rather than merely
 * refused, because a field a merchant cannot see is a field they cannot get wrong.
 *
 * Money is entered as a **decimal string** and parsed by `Money::fromDecimalString`,
 * which is string arithmetic. Nothing here goes through a float: `(int) (19.99 *
 * 100)` is 1998. `TextInput::integer()` is not used either — it hands back a float,
 * and the resulting `TypeError` surfaces as a bare 419.
 */
final class OfferTermsSchema
{
    /** @return array<int, Component> */
    public static function components(): array
    {
        return [
            Section::make('What the offer is')
                ->description('The merchant\'s standing rule. Editing it changes what happens next, never what already happened — every change is archived as a revision.')
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->helperText('Shown to staff, and recorded on every redemption.')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(2)
                        ->maxLength(1000),
                    Select::make('type')
                        ->label('Kind of reduction')
                        ->options(self::typeOptions())
                        ->default(OfferType::Percentage->value)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            // Free shipping targets shipping, and nothing else
                            // may. The domain refuses each half separately;
                            // moving the target here keeps the impossible pair
                            // unreachable rather than merely refused.
                            $set('target', $state === OfferType::FreeShipping->value
                                ? OfferTarget::Shipping->value
                                : OfferTarget::Order->value);
                        }),
                    Select::make('target')
                        ->label('What it reduces')
                        ->helperText('Decides where the money comes off, not just how much. An order offer is spread across every line; a product or collection offer is allocated only to the lines it names.')
                        ->options(fn (Get $get): array => self::targetOptions($get('type')))
                        ->default(OfferTarget::Order->value)
                        ->required()
                        ->live(),
                    Select::make('stacking')
                        ->label('Stacking')
                        ->helperText('Exclusive means that if this offer applies, nothing else may. There is no implicit answer and no global setting.')
                        ->options(self::stackingOptions())
                        ->default(StackingMode::Stackable->value)
                        ->required(),
                    TextInput::make('priority')
                        ->label('Priority')
                        ->helperText('Evaluated in ascending order, ties broken by offer id. Deterministic, so two offers that both apply give the same result on every run.')
                        ->numeric()
                        ->rule('integer')
                        ->default(0)
                        ->required(),
                ])
                ->columns(2),

            Section::make('How much it takes off')
                ->schema([
                    TextInput::make('value_basis_points')
                        ->label('Rate, in basis points')
                        ->helperText('20% is 2000. A rate is stored as basis points because a rate stored to two decimal places cannot express a third off.')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1)
                        ->maxValue(10000)
                        ->required(fn (Get $get): bool => self::usesBasisPoints($get('type')))
                        ->visible(fn (Get $get): bool => self::usesBasisPoints($get('type'))),
                    TextInput::make('value_amount')
                        ->label('Amount off')
                        ->helperText('A decimal amount in the currency below, for example 5.00.')
                        ->rules([self::decimalRule(positive: true)])
                        ->required(fn (Get $get): bool => $get('type') === OfferType::FixedAmount->value)
                        ->visible(fn (Get $get): bool => $get('type') === OfferType::FixedAmount->value),
                    TextInput::make('buy_quantity')
                        ->label('Buy')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1)
                        ->required(fn (Get $get): bool => $get('type') === OfferType::BuyXGetY->value)
                        ->visible(fn (Get $get): bool => $get('type') === OfferType::BuyXGetY->value),
                    TextInput::make('get_quantity')
                        ->label('Get')
                        ->helperText('The cheapest qualifying units are the ones discounted — the conventional rule, and the only one that does not reward a shopper for reordering their basket.')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1)
                        ->required(fn (Get $get): bool => $get('type') === OfferType::BuyXGetY->value)
                        ->visible(fn (Get $get): bool => $get('type') === OfferType::BuyXGetY->value),
                    TextInput::make('currency')
                        ->label('Currency')
                        ->helperText('Three letters. An offer denominated in a currency the basket is not does not apply.')
                        ->rule('regex:/^[A-Za-z]{3}$/')
                        ->required(fn (Get $get): bool => self::needsCurrency($get)),
                    Select::make('currency_exponent')
                        ->label('Minor unit digits')
                        ->helperText('2 for pounds and euros, 0 for yen.')
                        ->options([0 => '0', 1 => '1', 2 => '2', 3 => '3', 4 => '4'])
                        ->default(2)
                        ->live()
                        ->required(),
                ])
                ->columns(2),

            Section::make('Who and what qualifies')
                ->description('References are opaque. This module resolves none of them: product and collection references are resolved through a seam the host binds, and an offer naming one the host has not bound does not apply, visibly.')
                ->schema([
                    TagsInput::make('product_refs')
                        ->label('Product references')
                        ->required(fn (Get $get): bool => $get('target') === OfferTarget::Product->value)
                        ->visible(fn (Get $get): bool => $get('target') === OfferTarget::Product->value),
                    TagsInput::make('collection_refs')
                        ->label('Collection references')
                        ->required(fn (Get $get): bool => $get('target') === OfferTarget::Collection->value)
                        ->visible(fn (Get $get): bool => $get('target') === OfferTarget::Collection->value),
                    TagsInput::make('customer_group_refs')
                        ->label('Customer group references')
                        ->helperText('Leave empty for an offer open to everybody. Naming a group means the offer needs the customer-eligibility seam; with that seam unbound the offer is skipped as unresolvable rather than given away.'),
                    TextInput::make('minimum_subtotal')
                        ->label('Minimum basket subtotal')
                        ->helperText('A decimal amount in the currency above.')
                        ->rules([self::decimalRule()]),
                    TextInput::make('minimum_quantity')
                        ->label('Minimum basket quantity')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1),
                ])
                ->columns(2),

            Section::make('When and how often')
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Starts at')
                        ->seconds(false),
                    DateTimePicker::make('ends_at')
                        ->label('Ends at')
                        ->seconds(false)
                        ->after('starts_at'),
                    TextInput::make('max_redemptions')
                        ->label('Total redemption limit')
                        ->helperText('Enforced by a conditional update, so it is race-free. Releasing a redemption gives the use back.')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1),
                    TextInput::make('max_redemptions_per_customer')
                        ->label('Per-customer redemption limit')
                        ->helperText('Enforced by a unique index rather than a check. An offer with this limit does not apply to a basket with no customer, because an unenforceable control is not a satisfied one.')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1),
                ])
                ->columns(2),
        ];
    }

    /**
     * Form state to the domain's terms.
     *
     * Fields the chosen type has no room for are nulled rather than passed
     * through: the domain refuses a rate on a fixed-amount offer, and a merchant
     * who switched type after typing one should not be told off for a value they
     * can no longer see.
     *
     * @param  array<string, mixed>  $data
     */
    public static function toTerms(array $data): OfferTerms
    {
        $type = OfferType::from(self::string($data, 'type'));
        $target = OfferTarget::from(self::string($data, 'target'));
        $currency = strtoupper(trim(self::string($data, 'currency')));
        $exponent = self::int($data, 'currency_exponent') ?? 2;

        $money = static fn (?string $amount): ?Money => $amount === null || $currency === ''
            ? null
            : Money::fromDecimalString($amount, $currency, $exponent);

        return new OfferTerms(
            name: self::string($data, 'name'),
            type: $type,
            target: $target,
            stacking: StackingMode::from(self::string($data, 'stacking')),
            description: self::nullableString($data, 'description'),
            valueBasisPoints: $type->usesBasisPoints() ? self::int($data, 'value_basis_points') : null,
            valueAmount: $type === OfferType::FixedAmount ? $money(self::nullableString($data, 'value_amount')) : null,
            minimumSubtotal: $money(self::nullableString($data, 'minimum_subtotal')),
            minimumQuantity: self::int($data, 'minimum_quantity'),
            productRefs: $target === OfferTarget::Product ? self::refs($data, 'product_refs') : [],
            collectionRefs: $target === OfferTarget::Collection ? self::refs($data, 'collection_refs') : [],
            customerGroupRefs: self::refs($data, 'customer_group_refs'),
            buyQuantity: $type === OfferType::BuyXGetY ? self::int($data, 'buy_quantity') : null,
            getQuantity: $type === OfferType::BuyXGetY ? self::int($data, 'get_quantity') : null,
            priority: self::int($data, 'priority') ?? 0,
            startsAt: self::time($data, 'starts_at'),
            endsAt: self::time($data, 'ends_at'),
            maxRedemptions: self::int($data, 'max_redemptions'),
            maxRedemptionsPerCustomer: self::int($data, 'max_redemptions_per_customer'),
        );
    }

    /**
     * The live terms as form state.
     *
     * Read from `Offer::terms()`, which reads the columns — never the revision
     * archive. A second readable copy of the live terms is the fault this module
     * exists to avoid: the host carries the same fact in two columns five times
     * over in one table.
     *
     * @return array<string, mixed>
     */
    public static function fromOffer(Offer $offer): array
    {
        $terms = $offer->terms();

        return [
            'name' => $terms->name,
            'description' => $terms->description,
            'type' => $terms->type->value,
            'target' => $terms->target->value,
            'stacking' => $terms->stacking->value,
            'priority' => $terms->priority,
            'value_basis_points' => $terms->valueBasisPoints,
            'value_amount' => $terms->valueAmount?->decimal(),
            'minimum_subtotal' => $terms->minimumSubtotal?->decimal(),
            'currency' => $terms->currency(),
            'currency_exponent' => $terms->currencyExponent() ?? 2,
            'minimum_quantity' => $terms->minimumQuantity,
            'product_refs' => $terms->productRefs,
            'collection_refs' => $terms->collectionRefs,
            'customer_group_refs' => $terms->customerGroupRefs,
            'buy_quantity' => $terms->buyQuantity,
            'get_quantity' => $terms->getQuantity,
            'starts_at' => $terms->startsAt?->toDateTimeString(),
            'ends_at' => $terms->endsAt?->toDateTimeString(),
            'max_redemptions' => $terms->maxRedemptions,
            'max_redemptions_per_customer' => $terms->maxRedemptionsPerCustomer,
        ];
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            OfferType::Percentage->value => 'Percentage off',
            OfferType::FixedAmount->value => 'Fixed amount off',
            OfferType::FreeShipping->value => 'Free shipping',
            OfferType::BuyXGetY->value => 'Buy X get Y',
        ];
    }

    /** @return array<string, string> */
    public static function stackingOptions(): array
    {
        return [
            StackingMode::Stackable->value => 'Stackable',
            StackingMode::Exclusive->value => 'Exclusive',
        ];
    }

    /**
     * Shipping is offered to a free-shipping offer and to nothing else, in both
     * directions — the domain refuses each half separately.
     *
     * @return array<string, string>
     */
    public static function targetOptions(mixed $type): array
    {
        if ($type === OfferType::FreeShipping->value) {
            return [OfferTarget::Shipping->value => 'Shipping'];
        }

        return [
            OfferTarget::Order->value => 'The whole order',
            OfferTarget::Product->value => 'Named products',
            OfferTarget::Collection->value => 'Named collections',
        ];
    }

    private static function usesBasisPoints(mixed $type): bool
    {
        return is_string($type) && OfferType::tryFrom($type)?->usesBasisPoints() === true;
    }

    /** A currency is needed as soon as the offer names any amount at all. */
    private static function needsCurrency(Get $get): bool
    {
        return $get('type') === OfferType::FixedAmount->value || filled($get('minimum_subtotal'));
    }

    /**
     * Precision is validated by the domain's own parser rather than by a second
     * regex that would drift from it — a three-place amount in a two-place
     * currency is refused here for the same reason and with the same rule.
     */
    private static function decimalRule(bool $positive = false): Closure
    {
        return static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $positive): void {
            if (blank($value) || ! is_scalar($value)) {
                return;
            }

            $exponent = (int) ($get('currency_exponent') ?? 2);

            if (preg_match('/^\\d+(\\.\\d+)?$/', (string) $value) !== 1) {
                $fail('The :attribute must be a decimal amount, for example 5.00.');

                return;
            }

            try {
                $money = Money::fromDecimalString((string) $value, 'XXX', $exponent);
            } catch (InvalidArgumentException) {
                $fail("The :attribute carries more precision than a {$exponent}-place currency holds.");

                return;
            }

            if ($positive && $money->minor < 1) {
                $fail('The :attribute must be more than nothing.');
            }
        };
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return blank($value) || ! is_scalar($value) ? null : trim((string) $value);
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return blank($value) || ! is_scalar($value) ? null : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function refs(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $refs = [];

        foreach ($value as $ref) {
            if (is_scalar($ref) && trim((string) $ref) !== '') {
                $refs[] = trim((string) $ref);
            }
        }

        return array_values(array_unique($refs));
    }

    /** @param array<string, mixed> $data */
    private static function time(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        return blank($value) || ! is_scalar($value) ? null : CarbonImmutable::parse((string) $value);
    }
}
