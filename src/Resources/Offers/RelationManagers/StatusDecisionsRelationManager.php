<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;
use Liberu\Ecommerce\Promotions\Queries\ListOfferHistory;

/**
 * Who changed this offer's status, when, and why.
 *
 * A first-class surface rather than audit trivia: "who paused the Black Friday
 * sale, and when" is a question somebody asks at 9am on Black Friday, and the
 * host's answer is `discounts.is_active`, which records neither. This is the
 * append-only log `DecideOfferStatus` writes, and the status column on the offer
 * is a cache of its newest row.
 *
 * Fed by the domain's published query rather than by the relation, so the ordering
 * — by `occurred_at`, ties by id — is the domain's and not a second opinion. It is
 * read-only in every direction: no create, no edit, no delete, no bulk action, and
 * the record is not clickable, because there is nothing underneath to open.
 */
class StatusDecisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusDecisions';

    protected static ?string $title = 'Status decisions';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => App::make(ListOfferHistory::class)
                ->statusDecisions((int) $this->getOwnerRecord()->getKey())
                ->reverse()
                ->values())
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime(),
                TextColumn::make('to_status')
                    ->label('Became')
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state): string => ucfirst($state->value))
                    ->color(fn (OfferStatus $state): string => match ($state) {
                        OfferStatus::Active => 'success',
                        OfferStatus::Paused => 'warning',
                        OfferStatus::Ended => 'danger',
                        OfferStatus::Draft => 'gray',
                    }),
                TextColumn::make('from_status')
                    ->label('Was')
                    ->formatStateUsing(fn (?OfferStatus $state): string => $state instanceof OfferStatus ? ucfirst($state->value) : 'nothing')
                    ->placeholder('nothing'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->formatStateUsing(fn (OfferStatusReason $state): string => ucfirst(str_replace('_', ' ', $state->value))),
                TextColumn::make('actor_ref')
                    ->label('Who')
                    ->placeholder('not recorded'),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->placeholder('—'),
            ])
            // The three unwirings a custom-data table needs. The records here are
            // models, so the `Model`-typed defaults would survive — but an
            // append-only log has nothing to open, and leaving a row clickable
            // would offer an edit page that must never exist.
            ->recordAction(null)
            ->recordUrl(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No decisions recorded')
            ->modelLabel('status decision')
            ->pluralModelLabel('status decisions')
            ->recordTitle(fn (OfferStatusDecision $record): string => $record->to_status->value);
    }
}
