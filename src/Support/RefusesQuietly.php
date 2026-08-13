<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Support;

use Filament\Notifications\Notification;
use Liberu\Ecommerce\Promotions\Exceptions\PromotionsException;

/**
 * Turning a domain refusal into something a merchant can read.
 *
 * Every one of these is a **backstop**. The form mirrors the terms the domain
 * accepts, the code form refuses a duplicate before it is issued, and the release
 * action is hidden on a redemption that already has one — reaching any of these
 * means a rule drifted, and the merchant should see the reason rather than a 500
 * or, worse, a bare `419 Page Expired`.
 *
 * The exception's own message is shown. Every one of these is merchant-facing by
 * construction: this surface is the merchant's, and the domain's refusal reasons
 * are published for exactly it. The shopper-facing surface renders none of them.
 */
trait RefusesQuietly
{
    protected function refuse(PromotionsException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('That was refused')
            ->body($exception->getMessage())
            ->persistent()
            ->send();
    }
}
