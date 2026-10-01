<?php

namespace Modules\User\Services\Account;

use Modules\User\Models\User;

interface AccountServiceInterface
{
    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return User
     */
    public function getModel(): User;

    /**
     * Get user information
     *
     * @return User
     */
    public function me(): User;

    /**
     * Update the user's profile.
     *
     * @param array $data
     * @return User
     */
    public function updateProfile(array $data): User;

    /**
     * Update the user's password.
     *
     * @param array $data
     * @return bool
     */
    public function updatePassword(array $data): bool;

    public function beginMfaSetup(): array;

    public function confirmMfaSetup(string $code): array;

    public function disableMfa(): bool;

    /**
     * Logout the user from all other sessions/devices except current.
     *
     * @param User $user
     * @return void
     */
    public function logoutFromOtherDevices(User $user): void;
}
