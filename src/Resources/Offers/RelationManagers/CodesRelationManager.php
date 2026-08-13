<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Actions\IssueCode;
use Liberu\Ecommerce\Promotions\Exceptions\CodeAlreadyIssued;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Support\RefusesQuietly;
use Liberu\Ecommerce\Promotions\Models\Code;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * The ways this offer can be reached.
 *
 * A code is not the offer. One offer may be reachable by many codes — a
 * per-customer code, a campaign code, a partner code — or by none at all, which is
 * what an automatic discount is. The host's `coupons.code` is the primary key of
 * the concept in everything but name, which is why it can express neither case.
 *
 * **Codes are never searchable and never filterable here.** A search term and a
 * filter both persist into the query string, into browser history and into every
 * screenshot a merchant pastes into a ticket, and a promo code is a bearer-ish
 * value: whoever holds it can spend the offer. The column is shown, because a
 * merchant plainly needs to read their own codes, and it is reached by opening the
 * offer rather than by typing the code into a box.
 *
 * Uniqueness is enforced by the index rather than by a lookup first: a
 * check-then-insert is not a constraint. The duplicate is caught as
 * `CodeAlreadyIssued` and shown as a refusal.
 */
class CodesRelationManager extends RelationManager
{
    use RefusesQuietly;

    protected static string $relationship = 'codes';

    protected static ?string $title = 'Codes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->copyable(),
                TextColumn::make('created_at')
                    ->label('Issued')
                    ->dateTime(),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label('Issue a code')
                    ->authorize('create', Code::class)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->helperText('Case is not significant: SUMMER10 and summer10 are the same code, not two rows a merchant reads as a typo. Unique per merchant, so another merchant may use the same word.')
                            ->required()
                            ->maxLength(64),
                    ])
                    ->action(function (array $data): void {
                        $owner = $this->getOwnerRecord();

                        try {
                            App::make(IssueCode::class)(
                                PromotionsPlugin::current()->tenantId(),
                                $owner instanceof Offer ? $owner->id : (int) $owner->getKey(),
                                (string) $data['code'],
                            );
                        } catch (CodeAlreadyIssued $e) {
                            $this->refuse($e);

                            throw new Halt();
                        }

                        Notification::make()->success()->title('Code issued')->send();
                    }),
            ])
            // A code is never edited and never deleted: editing one silently
            // invalidates whatever a shopper is holding, and deleting one erases
            // the row a redemption points at. Withdrawing a code is expressed by
            // pausing or ending its offer, which is a recorded decision.
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
