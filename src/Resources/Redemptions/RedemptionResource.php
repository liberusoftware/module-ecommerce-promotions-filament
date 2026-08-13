<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Actions\ReleaseRedemption;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyReleased;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\Pages\ListRedemptions;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Models\RedemptionLine;

/**
 * The ledger: which offer was spent on which order, and what was given back.
 *
 * Append-only. Nothing here edits or deletes a redemption, because a usage limit
 * counts these rows and an accountant reconciles them — the host has no such
 * concept at all, inferring uses from a `SELECT COUNT(*)` over another module's
 * orders table, which is why a cancelled order can never give a use back.
 *
 * `order_ref` is an opaque string this module cannot resolve and never joins to.
 * It is searchable because it is the merchant's own order identifier and the
 * ledger is unusable without it. `customer_ref` is neither searchable nor
 * filterable: a search term and a filter both persist into the query string, and a
 * shopper reference is not a thing to leave in browser history. It is shown on the
 * record, where a merchant already opened the row that carries it.
 */
class RedemptionResource extends Resource
{
    protected static ?string $model = Redemption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Redemptions';

    public static function getNavigationGroup(): ?string
    {
        return 'Promotions';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', PromotionsPlugin::current()->tenantId())
            ->with(['offer', 'lines', 'release', 'code']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_ref')
                    ->label('Order')
                    ->searchable()
                    ->description('Opaque: nothing here resolves it'),
                TextColumn::make('offer.name')
                    ->label('Offer'),
                TextColumn::make('total')
                    ->label('Reduction')
                    ->state(fn (Redemption $record): string => $record->total()->currency.' '.$record->total()->decimal()),
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('release.reason')
                    ->label('Released')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (ReleaseReason $state): string => self::releaseLabel($state))
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('released')
                    ->label('Released')
                    ->placeholder('Any')
                    ->trueLabel('Given back')
                    ->falseLabel('Still spent')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('release'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('release'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('offer_id')
                    ->label('Offer')
                    ->relationship('offer', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::releaseAction(),
            ])
            // Append-only: there is nothing to delete in bulk, and a release is a
            // decision about one redemption with a reason of its own.
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The redemption')
                ->schema([
                    TextEntry::make('order_ref')->label('Order reference'),
                    TextEntry::make('offer.name')->label('Offer'),
                    TextEntry::make('revision.revision_number')
                        ->label('Evaluated under revision')
                        ->helperText('An edit changes what happens next, never what already happened.'),
                    TextEntry::make('code.code')->label('Code')->placeholder('no code — an automatic offer'),
                    TextEntry::make('customer_ref')
                        ->label('Customer reference')
                        ->placeholder('none, or redacted'),
                    TextEntry::make('customer_sequence')
                        ->label('Per-customer slot')
                        ->helperText('A constraint slot rather than a fact. Releasing clears it so the slot returns; it is not "which use this was".')
                        ->placeholder('returned'),
                    TextEntry::make('occurred_at')->label('Occurred at')->dateTime(),
                    TextEntry::make('total')
                        ->label('Total reduction')
                        ->state(fn (Redemption $record): string => $record->total()->currency.' '.$record->total()->decimal()),
                    TextEntry::make('shipping_reduction_minor')
                        ->label('Of which shipping')
                        ->state(fn (Redemption $record): string => Money::fromMinor(
                            $record->shipping_reduction_minor,
                            $record->currency,
                            $record->currency_exponent,
                        )->decimal())
                        ->helperText('Published separately from the lines, because shipping is taxed and refunded differently from goods.'),
                ])
                ->columns(2),
            Section::make('Where the money came off')
                ->description('The allocation the domain published. Refunding one line of a discounted order needs this; re-deriving it differently is a penny of permanent disagreement.')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('Lines')
                        ->schema([
                            TextEntry::make('line_ref')->label('Line'),
                            TextEntry::make('product_ref')->label('Product')->placeholder('—'),
                            TextEntry::make('amount_minor')
                                ->label('Amount')
                                ->state(fn (RedemptionLine $record): string => Money::fromMinor(
                                    $record->amount_minor,
                                    $record->redemption->currency,
                                    $record->redemption->currency_exponent,
                                )->decimal()),
                        ])
                        ->columns(3),
                ]),
            Section::make('The release')
                ->description('A use given back. Its own append-only record, unique per redemption, so "spent then returned" and "never spent" stay distinguishable.')
                ->visible(fn (Redemption $record): bool => $record->release !== null)
                ->schema([
                    TextEntry::make('release.reason')
                        ->label('Reason')
                        ->formatStateUsing(fn (ReleaseReason $state): string => self::releaseLabel($state)),
                    TextEntry::make('release.actor_ref')->label('Who')->placeholder('not recorded'),
                    TextEntry::make('release.occurred_at')->label('When')->dateTime(),
                    TextEntry::make('release.note')->label('Note')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Giving a use back.
     *
     * Not an edit and not a deletion: `ReleaseRedemption` appends a release row,
     * clears the per-customer slot so it returns, and decrements the counter by
     * the same conditional update that claimed it. The unique index on
     * `redemption_id` is what makes it happen at most once, which is why the
     * duplicate is caught rather than guarded against.
     */
    public static function releaseAction(): Action
    {
        return Action::make('release')
            ->label('Release')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize('release')
            ->visible(fn (Redemption $record): bool => $record->release === null)
            ->schema([
                Select::make('reason')
                    ->label('Why')
                    ->options(self::releaseReasons())
                    ->required(),
                Textarea::make('note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (Redemption $record, array $data): void {
                try {
                    App::make(ReleaseRedemption::class)(
                        $record->id,
                        ReleaseReason::from((string) $data['reason']),
                        PromotionsPlugin::current()->actorRef(),
                        blank($data['note'] ?? null) ? null : (string) $data['note'],
                    );
                } catch (RedemptionAlreadyReleased $e) {
                    Notification::make()->danger()->title('That was refused')->body($e->getMessage())->persistent()->send();

                    throw new Halt();
                }

                Notification::make()
                    ->success()
                    ->title('Use given back')
                    ->body('The offer\'s counter has come back down and the per-customer slot has returned.')
                    ->send();
            });
    }

    /** @return array<string, string> */
    public static function releaseReasons(): array
    {
        $reasons = [];

        foreach (ReleaseReason::cases() as $reason) {
            $reasons[$reason->value] = self::releaseLabel($reason);
        }

        return $reasons;
    }

    public static function releaseLabel(ReleaseReason $reason): string
    {
        return match ($reason) {
            ReleaseReason::OrderCancelled => 'Order cancelled',
            ReleaseReason::OrderRefunded => 'Order refunded',
            ReleaseReason::MerchantReversed => 'Merchant reversed it',
            ReleaseReason::PaymentFailed => 'Payment failed',
        };
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListRedemptions::route('/'),
        ];
    }
}
