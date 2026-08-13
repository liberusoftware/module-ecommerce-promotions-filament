<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Promotions\Actions\DecideOfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Filament\PromotionsPlugin;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * Changing an offer's status, the only way it may be changed.
 *
 * Every one of these calls `DecideOfferStatus`, which appends a row to
 * `promotions_offer_status_decisions` carrying the previous status, the new one, a
 * closed-enum reason, the actor and an `occurred_at`, and only then updates the
 * cached column. Nothing in this panel writes `promotions_offers.status`, and the
 * form does not offer it as a field.
 *
 * The reason is derived from where the offer is coming from rather than asked for:
 * activating a draft and resuming a pause are different facts, and a merchant
 * should not have to classify their own click. The optional note is theirs.
 */
final class StatusActions
{
    /** Row actions for the offers table. @return array<int, Action | ActionGroup> */
    public static function forTable(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make(self::decisions())
                ->label('Status')
                ->icon(Heroicon::OutlinedFlag)
                ->button()
                ->outlined(),
        ];
    }

    /** @return array<int, Action> */
    public static function decisions(): array
    {
        return [
            self::activate(),
            self::pause(),
            self::end(),
        ];
    }

    private static function activate(): Action
    {
        return self::decision(
            name: 'activate',
            label: 'Activate',
            icon: Heroicon::OutlinedPlayCircle,
            colour: 'success',
            to: OfferStatus::Active,
            from: [OfferStatus::Draft, OfferStatus::Paused],
        );
    }

    private static function pause(): Action
    {
        return self::decision(
            name: 'pause',
            label: 'Pause',
            icon: Heroicon::OutlinedPauseCircle,
            colour: 'warning',
            to: OfferStatus::Paused,
            from: [OfferStatus::Active],
        );
    }

    private static function end(): Action
    {
        return self::decision(
            name: 'end',
            label: 'End',
            icon: Heroicon::OutlinedStopCircle,
            colour: 'danger',
            to: OfferStatus::Ended,
            from: [OfferStatus::Draft, OfferStatus::Active, OfferStatus::Paused],
        );
    }

    /** @param list<OfferStatus> $from */
    private static function decision(
        string $name,
        string $label,
        Heroicon $icon,
        string $colour,
        OfferStatus $to,
        array $from,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($colour)
            // Actions carry no automatic policy authorization: Filament's default
            // is `null`, which is allowed for everybody. Named explicitly.
            ->authorize('decideStatus')
            ->visible(fn (Offer $record): bool => in_array($record->status, $from, true))
            ->requiresConfirmation()
            ->modalHeading(fn (Offer $record): string => $label.' “'.$record->name.'”')
            ->modalDescription('This is recorded in the decision log with your name and the time.')
            ->schema([
                Textarea::make('note')
                    ->label('Note (optional)')
                    ->helperText('Kept on the decision, for whoever asks why at 9am on Black Friday.')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (Offer $record, array $data) use ($to): void {
                $plugin = PromotionsPlugin::current();

                App::make(DecideOfferStatus::class)(
                    $plugin->tenantId(),
                    $record->id,
                    $to,
                    self::reasonFor($record->status, $to),
                    $plugin->actorRef(),
                    blank($data['note'] ?? null) ? null : (string) $data['note'],
                );

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Recorded in the decision log')
                    ->body('“'.$record->name.'” is now '.$record->status->value.'.')
                    ->send();
            });
    }

    /**
     * Resuming a pause is not the same fact as activating a draft, and the domain
     * has a separate reason for each. Deriving it from the transition keeps the
     * log honest without asking a merchant to classify their own click.
     */
    private static function reasonFor(OfferStatus $from, OfferStatus $to): OfferStatusReason
    {
        return match (true) {
            $to === OfferStatus::Ended => OfferStatusReason::MerchantEnded,
            $to === OfferStatus::Paused => OfferStatusReason::MerchantPaused,
            $to === OfferStatus::Active && $from === OfferStatus::Paused => OfferStatusReason::MerchantResumed,
            default => OfferStatusReason::MerchantActivated,
        };
    }
}
