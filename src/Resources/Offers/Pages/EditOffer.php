<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Actions\ReviseOfferTerms;
use Liberu\Ecommerce\Promotions\Exceptions\InvalidOfferTerms;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\OfferResource;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Schemas\OfferTermsSchema;
use Liberu\Ecommerce\Promotions\Filament\Support\RefusesQuietly;
use Liberu\Ecommerce\Promotions\Filament\Support\StatusActions;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * Editing changes what happens next, never what already happened.
 *
 * `ReviseOfferTerms` archives the new terms as a revision and moves the live
 * columns to match. Every redemption already recorded still names the revision it
 * was evaluated under, which is what makes that claim provable rather than
 * promised — so saving through Eloquent here would quietly break it.
 *
 * The status actions live in the header rather than the form, because a status is
 * a decision with an actor and a reason, not a column.
 */
class EditOffer extends EditRecord
{
    use RefusesQuietly;

    protected static string $resource = OfferResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return StatusActions::decisions();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return $record instanceof Offer ? OfferTermsSchema::fromOffer($record) : $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $plugin = PromotionsPlugin::current();

        try {
            App::make(ReviseOfferTerms::class)(
                $plugin->tenantId(),
                (int) $record->getKey(),
                OfferTermsSchema::toTerms($data),
                $plugin->actorRef(),
            );
        } catch (InvalidOfferTerms $e) {
            $this->refuse($e);

            throw new Halt();
        }

        return $record->refresh();
    }
}
