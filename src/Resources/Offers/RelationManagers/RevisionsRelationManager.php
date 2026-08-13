<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers;

use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Liberu\Ecommerce\Promotions\Queries\ListOfferHistory;

/**
 * What this offer's terms were, at every revision.
 *
 * A first-class surface, not audit trivia. Every redemption records the revision
 * it was evaluated under, and that is what makes "an edit changes the future, not
 * the past" a provable claim rather than a promise — so a merchant reconciling a
 * redemption has to be able to read the terms it was evaluated against.
 *
 * **This is the archive, and evaluation never reads it.** A second readable copy of
 * the live terms is the host's fault with better provenance: `promotions_offers`
 * carries the live terms and this table carries what they used to be, and nothing
 * here is offered as a source for either.
 *
 * Read-only in every direction. A revision cannot be created, edited, deleted or
 * reverted from here: reverting is authoring the old terms again, which is a new
 * revision with a new actor and a new time, and it goes through the offer form.
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Revisions';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => App::make(ListOfferHistory::class)
                ->revisions((int) $this->getOwnerRecord()->getKey())
                ->reverse()
                ->values())
            ->columns([
                TextColumn::make('revision_number')
                    ->label('Revision')
                    ->badge(),
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime(),
                TextColumn::make('actor_ref')
                    ->label('Who')
                    ->placeholder('not recorded'),
                TextColumn::make('terms.name')
                    ->label('Name at the time'),
            ])
            ->recordActions([
                Action::make('terms')
                    ->label('Terms')
                    ->authorize('view')
                    ->modalHeading(fn (OfferRevision $record): string => 'Terms at revision '.$record->revision_number)
                    ->modalSubmitAction(false)
                    ->infolist([
                        KeyValueEntry::make('terms')
                            ->label('Archived terms')
                            ->keyLabel('Term')
                            ->valueLabel('Value')
                            ->state(fn (OfferRevision $record): array => self::readable($record)),
                    ]),
            ])
            // The two `Model`-typed defaults, replaced. The row itself is not
            // clickable: an archived revision has no page of its own, and the
            // action above is the only thing it opens.
            ->recordAction(null)
            ->recordUrl(null)
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No revisions recorded')
            ->modelLabel('revision')
            ->pluralModelLabel('revisions')
            ->recordTitle(fn (OfferRevision $record): string => 'Revision '.$record->revision_number);
    }

    /**
     * The archived snapshot, flattened for display.
     *
     * `OfferTerms::toArray()` nests a money value and three reference lists. The
     * money keeps the shape it was archived in — minor units, a currency and an
     * exponent — and its decimal is the string the domain published, never a
     * float this surface computed.
     *
     * @return array<string, string>
     */
    private static function readable(OfferRevision $record): array
    {
        $readable = [];

        foreach ($record->terms as $term => $value) {
            $readable[str_replace('_', ' ', $term)] = match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'yes' : 'no',
                is_array($value) => isset($value['decimal'], $value['currency'])
                    ? $value['currency'].' '.$value['decimal']
                    : ($value === [] ? '—' : implode(', ', array_map(strval(...), $value))),
                default => (string) $value,
            };
        }

        return $readable;
    }
}
