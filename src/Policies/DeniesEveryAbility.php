<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Filament\Policies;

/**
 * Every ability Filament asks about, answered `false` **by name**.
 *
 * Three separate defaults are permissive and each one has shipped as a hole in
 * this programme:
 *
 * 1. a model with no policy at all — Laravel's unanswered gate allows;
 * 2. a policy present but missing the method asked about — Filament's
 *    `get_authorization_response()` returns *allow*;
 * 3. `associate` / `dissociate`, which are live on a `hasMany` relation manager
 *    and open by default.
 *
 * The host's `DiscountResource` is registered in the admin panel with no policy
 * at all, which is the sixth instance of the first case in this repository.
 *
 * The ability list is exactly the set of strings Filament's resource and
 * relation-manager authorization passes to the gate, so a subclass publishing an
 * ability does it by overriding a method that already exists rather than by
 * inventing a name nothing asks about.
 *
 * This is a class rather than a trait deliberately: a subclass method silently
 * wins over a trait's, so a policy that meant to deny would reopen an ability by
 * naming a method that happened to collide. Overriding a parent method is the
 * same act, but it is one a reader can see.
 *
 * Parameters are `mixed` on purpose. Narrowing a parameter type in a subclass is
 * a fatal at class load, not a test failure, so the published overrides keep
 * these signatures and name their model in the docblock instead.
 */
abstract class DeniesEveryAbility
{
    public function viewAny(mixed $user): bool
    {
        return false;
    }

    public function view(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function create(mixed $user): bool
    {
        return false;
    }

    public function update(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function delete(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function deleteAny(mixed $user): bool
    {
        return false;
    }

    public function forceDelete(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function forceDeleteAny(mixed $user): bool
    {
        return false;
    }

    public function restore(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function restoreAny(mixed $user): bool
    {
        return false;
    }

    public function replicate(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function reorder(mixed $user): bool
    {
        return false;
    }

    public function attach(mixed $user): bool
    {
        return false;
    }

    public function detach(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function detachAny(mixed $user): bool
    {
        return false;
    }

    public function associate(mixed $user): bool
    {
        return false;
    }

    public function dissociate(mixed $user, mixed $record): bool
    {
        return false;
    }

    public function dissociateAny(mixed $user): bool
    {
        return false;
    }

    /** Every ability this policy answers, so a test can assert the whole set. */
    final public const ABILITIES = [
        'viewAny', 'view', 'create', 'update',
        'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny',
        'restore', 'restoreAny', 'replicate', 'reorder',
        'attach', 'detach', 'detachAny',
        'associate', 'dissociate', 'dissociateAny',
    ];

    /** Abilities taking only the actor; the rest also take a record. */
    final public const RECORDLESS_ABILITIES = [
        'viewAny', 'create', 'deleteAny', 'forceDeleteAny',
        'restoreAny', 'reorder', 'attach', 'detachAny',
        'associate', 'dissociateAny',
    ];
}
