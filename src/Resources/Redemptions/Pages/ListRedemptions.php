<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Promotions\Filament\Resources\Redemptions\RedemptionResource;
use Liberu\Ecommerce\Promotions\Filament\Widgets\LedgerIntegrity;

class ListRedemptions extends ListRecords
{
    protected static string $resource = RedemptionResource::class;

    /** @return array<int, class-string> */
    protected function getHeaderWidgets(): array
    {
        return [LedgerIntegrity::class];
    }
}
