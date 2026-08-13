<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Actions\CreateOffer as CreateOfferAction;
use Liberu\Ecommerce\Promotions\Exceptions\InvalidOfferTerms;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\OfferResource;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Schemas\OfferTermsSchema;
use Liberu\Ecommerce\Promotions\Filament\Support\RefusesQuietly;

/**
 * Authoring goes through the domain action, never through Eloquent.
 *
 * `CreateOffer` writes the offer, its first revision and the decision that created
 * it, in one transaction. Writing the row here instead would produce an offer with
 * no revision to point a redemption at and no decision saying it exists — the
 * archive would start one edit late.
 *
 * An offer starts as a draft. Nothing evaluates it until somebody decides to
 * activate it, and that decision is recorded with an actor.
 */
class CreateOffer extends CreateRecord
{
    use RefusesQuietly;

    protected static string $resource = OfferResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $plugin = PromotionsPlugin::current();

        try {
            return App::make(CreateOfferAction::class)(
                $plugin->tenantId(),
                OfferTermsSchema::toTerms($data),
                $plugin->actorRef(),
            );
        } catch (InvalidOfferTerms $e) {
            // A backstop, not a UX. Every rule the domain enforces has a
            // counterpart in the form; reaching this means one of them drifted,
            // and a merchant should see the reason rather than a 500.
            $this->refuse($e);

            throw new Halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
