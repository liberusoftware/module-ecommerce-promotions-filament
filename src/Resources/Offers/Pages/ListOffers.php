<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Offers\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Liberu\Ecommerce\Promotions\Filament\Resources\Offers\OfferResource;
use Liberu\Ecommerce\Promotions\Filament\Widgets\LedgerIntegrity;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('evaluate')
                ->label('Evaluate a basket')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->url(fn (): string => OfferResource::getUrl('evaluate')),
        ];
    }

    /** @return array<int, class-string> */
    protected function getHeaderWidgets(): array
    {
        return [LedgerIntegrity::class];
    }
}
