<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\CreateOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EditOffer;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\EvaluateBasket;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages\ListOffers;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers\CodesRelationManager;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers\RevisionsRelationManager;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers\StatusDecisionsRelationManager;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Schemas\OfferTermsSchema;
use Liberu\Ecommerce\Promotions\Filament\Support\StatusActions;
use Liberu\Ecommerce\Promotions\Filament\Support\Terms;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * The merchant's standing rules.
 *
 * The model is declared because Filament's resources, policies, relation managers
 * and record routing are all typed against one — there is no model-less resource.
 * Eloquent is kept to tenant-scoped route binding and listing; nothing here
 * recomputes a state, an allocation or an aggregate the domain already publishes.
 *
 * **Status is not a form field.** It is decided through `DecideOfferStatus`, which
 * writes an append-only decision with an actor, a time and a closed-enum reason.
 * The host's answer to "who paused the Black Friday sale, and when" is
 * `discounts.is_active`, which records neither.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Offers';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return 'Promotions';
    }

    /**
     * Tenant scoping, applied once at the root of every read this resource makes
     * — the list, the record binding and every relation manager underneath it.
     *
     * A tenant column is never null here, so this is a plain equality rather than
     * the host's `where('col', null)` scope, which compiles to `is null` and lists
     * exactly the orphan rows a policy denies.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', PromotionsPlugin::current()->tenantId());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(OfferTermsSchema::components());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    // The merchant's own label for their own rule. A code is not
                    // searchable here and never will be: a search term persists
                    // into the query string, and a code is a bearer-ish value.
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state): string => ucfirst($state->value))
                    ->color(fn (OfferStatus $state): string => match ($state) {
                        OfferStatus::Active => 'success',
                        OfferStatus::Paused => 'warning',
                        OfferStatus::Ended => 'danger',
                        OfferStatus::Draft => 'gray',
                    }),
                TextColumn::make('type')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (OfferType $state): string => OfferTermsSchema::typeOptions()[$state->value]),
                TextColumn::make('value')
                    ->label('Reduction')
                    ->state(fn (Offer $record): string => Terms::describe($record))
                    ->description(fn (Offer $record): string => Terms::describeTarget($record)),
                TextColumn::make('stacking')
                    ->label('Stacking')
                    ->formatStateUsing(fn (StackingMode $state): string => ucfirst($state->value))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priority')
                    ->label('Priority')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('redemptions_used')
                    ->label('Redeemed')
                    ->state(fn (Offer $record): string => Terms::describeUsage($record))
                    ->description('Live uses, releases already given back'),
                TextColumn::make('revision_number')
                    ->label('Revision')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                // Nothing sensitive is filterable: filter state persists into the
                // query string exactly as a search term does.
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => array_combine(
                        array_map(fn (OfferStatus $status): string => $status->value, OfferStatus::cases()),
                        array_map(fn (OfferStatus $status): string => ucfirst($status->value), OfferStatus::cases()),
                    )),
                SelectFilter::make('type')
                    ->label('Kind')
                    ->options(OfferTermsSchema::typeOptions()),
                SelectFilter::make('target')
                    ->label('Target')
                    ->options(array_combine(
                        array_map(fn (OfferTarget $target): string => $target->value, OfferTarget::cases()),
                        array_map(fn (OfferTarget $target): string => ucfirst($target->value), OfferTarget::cases()),
                    )),
            ])
            ->recordActions(StatusActions::forTable())
            // No bulk actions at all. Every one Filament ships either deletes or
            // restores, and an offer is neither deleted nor restored — it is
            // ended, by a recorded decision.
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            CodesRelationManager::class,
            StatusDecisionsRelationManager::class,
            RevisionsRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
            'create' => CreateOffer::route('/create'),
            'evaluate' => EvaluateBasket::route('/evaluate'),
            'edit' => EditOffer::route('/{record}/edit'),
        ];
    }
}
