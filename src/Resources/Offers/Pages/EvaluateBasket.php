<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Data\Basket;
use Liberu\Ecommerce\Promotions\Data\BasketLine;
use Liberu\Ecommerce\Promotions\Data\Entitlement;
use Liberu\Ecommerce\Promotions\Data\LineAllocation;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\OfferResource;
use Liberu\Ecommerce\Promotions\Filament\Support\Refusals;

/**
 * Why an offer did not apply — the surface addendum §6 asks for.
 *
 * A merchant describes a basket and sees every active offer that was considered,
 * each one either applied with its allocation or skipped **by name, with its
 * reason**. A skipped offer that reads as an ordinary non-qualification is exactly
 * the failure mode the domain's separate `eligibility_unresolvable` reason exists
 * to prevent, so the two are styled differently here.
 *
 * `QuoteBasket` writes nothing, stores nothing and reserves nothing. The result is
 * perishable and is recomputed on every render: there is no cached entitlement on
 * this page, because a cached entitlement is the host's session copy of an applied
 * discount, which is the fault this module exists to remove.
 *
 * **This is a custom-data table, and three separate things need unwiring for one.**
 * Each fails on its own:
 *
 * 1. `ListRecords::makeTable()` attaches a `recordAction` closure typed against
 *    `Model`. These records are arrays, so it is replaced with `null`.
 * 2. It attaches a `recordUrl` closure typed against `Model` too, unless the table
 *    already declares a custom one — passing `null` counts as declaring one.
 * 3. `ViewAction` authorizes against a `Model`, so this table ships no record
 *    actions at all; the offer itself is reached from the offers list.
 *
 * The resource still declares a model, because Filament's resources, policies,
 * relation managers and record routing are all typed against one. Eloquent stays
 * where it belongs: tenant-scoped route binding and the offers list.
 */
class EvaluateBasket extends ListRecords
{
    protected static string $resource = OfferResource::class;

    /**
     * The basket being evaluated, as the merchant described it.
     *
     * Not a money value the domain trusts and not an entitlement: the amounts are
     * re-parsed to minor units and re-quoted on every render.
     *
     * @var array<string, mixed>|null
     */
    public ?array $basketInput = null;

    public function getTitle(): string
    {
        return 'Evaluate a basket';
    }

    public function getSubheading(): ?string
    {
        return 'Nothing here is written, stored or reserved. An entitlement is perishable: a basket that shrinks loses the one it had.';
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('describeBasket')
                ->label($this->basketInput === null ? 'Describe a basket' : 'Change the basket')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->modalHeading('The basket to evaluate')
                ->modalSubmitActionLabel('Evaluate')
                ->fillForm(fn (): array => $this->basketInput ?? self::emptyBasket())
                ->schema(self::basketSchema())
                ->action(function (array $data): void {
                    $this->basketInput = $data;
                    $this->resetTable();
                }),
            Action::make('back')
                ->label('Back to offers')
                ->color('gray')
                ->url(fn (): string => OfferResource::getUrl('index')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->heading('Every active offer, and what happened to it')
            ->description(fn (): ?string => $this->codeSummary())
            ->columns([
                TextColumn::make('offer')
                    ->label('Offer'),
                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->color(fn (array $record): string => match ($record['outcome']) {
                        'Applied' => 'success',
                        'Could not be evaluated' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('reduction')
                    ->label('Reduction')
                    ->placeholder('—'),
                TextColumn::make('detail')
                    ->label('Why, and where the money came off')
                    ->wrap(),
                TextColumn::make('code')
                    ->label('Code')
                    ->placeholder('—'),
            ])
            // Unwiring 1 and 2: both defaults are typed against `Model`, and these
            // records are arrays. Unwiring 3 is the absence of record actions —
            // `ViewAction` authorizes against a `Model` as well.
            ->recordAction(null)
            ->recordUrl(null)
            ->recordActions([])
            ->toolbarActions([])
            ->paginated(false)
            ->emptyStateHeading($this->basketInput === null ? 'No basket described yet' : 'No active offers to consider')
            ->emptyStateDescription($this->basketInput === null
                ? 'Describe a basket and every active offer will be evaluated against it.'
                : 'Every offer in this merchant is a draft, paused or ended.')
            ->emptyStateIcon(Heroicon::OutlinedShoppingCart);
    }

    /**
     * One row per offer the quote considered, applied or skipped.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(): array
    {
        $entitlement = $this->entitlement();

        if (! $entitlement instanceof Entitlement) {
            return [];
        }

        $rows = [];

        foreach ($entitlement->applied as $applied) {
            $rows[] = [
                '__key' => 'offer-'.$applied->offerId,
                'offer' => $applied->offerName,
                'outcome' => 'Applied',
                'reduction' => Money::fromMinor(
                    $applied->totalMinor(),
                    $entitlement->currency,
                    $entitlement->currencyExponent,
                )->decimal(),
                'detail' => self::allocationSummary($applied->lines, $applied->shippingReductionMinor, $entitlement),
                'code' => $applied->code,
            ];
        }

        foreach ($entitlement->skipped as $skipped) {
            $rows[] = [
                '__key' => 'offer-'.$skipped->offerId,
                'offer' => $skipped->offerName,
                'outcome' => Refusals::needsAttention($skipped->reason) ? 'Could not be evaluated' : 'Skipped',
                'reduction' => null,
                'detail' => Refusals::label($skipped->reason),
                'code' => null,
            ];
        }

        return $rows;
    }

    /** The refused and honoured codes, which the merchant may see and a shopper may not. */
    protected function codeSummary(): ?string
    {
        $entitlement = $this->entitlement();

        if (! $entitlement instanceof Entitlement) {
            return null;
        }

        $parts = [];

        if ($entitlement->honouredCodes !== []) {
            $parts[] = 'Honoured: '.implode(', ', $entitlement->honouredCodes);
        }

        foreach ($entitlement->refusedCodes as $code => $reason) {
            $parts[] = 'Refused '.$code.': '.Refusals::label($reason);
        }

        $parts[] = 'Total reduction: '.$entitlement->total()->currency.' '.$entitlement->total()->decimal();

        return implode(' · ', $parts);
    }

    /**
     * The quote itself. Recomputed every time it is asked for, never held.
     *
     * A basket the merchant typed badly is refused here rather than thrown: the
     * domain's constructors validate their own arguments, and an unusable basket
     * is a form problem, not a page crash.
     */
    protected function entitlement(): ?Entitlement
    {
        if ($this->basketInput === null) {
            return null;
        }

        try {
            $basket = self::toBasket($this->basketInput);
        } catch (InvalidArgumentException) {
            return null;
        }

        $codes = array_values(array_filter(
            array_map(strval(...), (array) ($this->basketInput['codes'] ?? [])),
            static fn (string $code): bool => trim($code) !== '',
        ));

        return App::make(QuoteBasket::class)(
            PromotionsPlugin::current()->tenantId(),
            $basket,
            $codes,
        );
    }

    /** @param array<string, mixed> $data */
    protected static function toBasket(array $data): Basket
    {
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'GBP')));
        $exponent = (int) ($data['currency_exponent'] ?? 2);
        $minor = static fn (mixed $amount): int => Money::fromDecimalString(
            blank($amount) ? '0' : (string) $amount,
            $currency,
            $exponent,
        )->minor;

        $lines = [];
        $index = 0;

        foreach ((array) ($data['lines'] ?? []) as $line) {
            $index++;

            if (! is_array($line)) {
                continue;
            }

            $lines[] = new BasketLine(
                // Generated rather than typed: a basket refuses a duplicate line
                // reference, and there is nothing for a merchant to gain by
                // choosing one on a page that writes nothing.
                lineRef: 'line-'.$index,
                productRef: (string) ($line['product_ref'] ?? ''),
                quantity: (int) ($line['quantity'] ?? 1),
                unitAmountMinor: $minor($line['unit_amount'] ?? '0'),
            );
        }

        return new Basket(
            currency: $currency,
            lines: $lines,
            shippingMinor: $minor($data['shipping'] ?? '0'),
            customerRef: blank($data['customer_ref'] ?? null) ? null : (string) $data['customer_ref'],
            currencyExponent: $exponent,
        );
    }

    /**
     * Where one offer's money came off.
     *
     * Read from the allocation the domain published; nothing here re-derives it.
     * The tax engine spreads a discount pro-rata with untaxable lines in the
     * denominator, and refunding one line of a discounted order needs to know how
     * much of the discount that line carried — so an allocation that a surface
     * re-derived differently is a penny of permanent disagreement.
     *
     * @param  list<LineAllocation>  $lines
     */
    protected static function allocationSummary(array $lines, int $shippingMinor, Entitlement $entitlement): string
    {
        $money = static fn (int $amount): string => Money::fromMinor(
            $amount,
            $entitlement->currency,
            $entitlement->currencyExponent,
        )->decimal();

        $parts = array_map(
            static fn (LineAllocation $line): string => $line->lineRef.' ('.$line->productRef.') '.$money($line->amountMinor),
            $lines,
        );

        if ($shippingMinor > 0) {
            $parts[] = 'shipping '.$money($shippingMinor);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /** @return array<string, mixed> */
    protected static function emptyBasket(): array
    {
        return [
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'shipping' => '0.00',
            'customer_ref' => null,
            'codes' => [],
            'lines' => [],
        ];
    }

    /** @return array<int, Component> */
    protected static function basketSchema(): array
    {
        return [
            TextInput::make('currency')
                ->label('Currency')
                ->default('GBP')
                ->rule('regex:/^[A-Za-z]{3}$/')
                ->required(),
            Select::make('currency_exponent')
                ->label('Minor unit digits')
                ->options([0 => '0', 1 => '1', 2 => '2', 3 => '3', 4 => '4'])
                ->default(2)
                ->required(),
            TextInput::make('shipping')
                ->label('Shipping charge')
                ->helperText('A decimal amount, for example 4.99.')
                ->rule('regex:/^\d+(\.\d+)?$/')
                ->default('0.00')
                ->required(),
            TextInput::make('customer_ref')
                ->label('Customer reference')
                ->helperText('Optional. An offer with a per-customer limit or a named group does not apply to a basket with no customer, because an unenforceable control is not a satisfied one.'),
            TagsInput::make('codes')
                ->label('Codes presented'),
            Repeater::make('lines')
                ->label('Basket lines')
                ->addActionLabel('Add a line')
                ->schema([
                    TextInput::make('product_ref')
                        ->label('Product reference')
                        ->helperText('Opaque. Nothing here resolves it.')
                        ->required(),
                    TextInput::make('quantity')
                        ->label('Quantity')
                        ->numeric()
                        ->rule('integer')
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    TextInput::make('unit_amount')
                        ->label('Unit amount')
                        ->helperText('A decimal amount, for example 19.99.')
                        ->rule('regex:/^\d+(\.\d+)?$/')
                        ->required(),
                ])
                ->columns(3),
        ];
    }
}
