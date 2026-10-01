<?php

namespace Modules\User\Services\Auth;

use Modules\User\Models\User;

interface AuthServiceInterface
{
    /**
     * Login user
     *
     * @param array $credentials
     * @return array
     */
    public function login(array $credentials): array;

    public function verifyMfaLogin(array $data): array;

    /**
     * Logout
     *
     * @return bool
     */
    public function logout(): bool;

    /**
     * Check if the identifier is email value
     *
     * @param string $identifier
     * @return bool
     */
    public function isEmail(string $identifier): bool;

    /**
     * Validate the user status
     *
     * @param User|null $user
     * @return void
     */
    public function validateUserStatus(?User $user): void;

    /**
     * Grant user auth response
     *
     * @param User $user
     * @param bool $remember
     * @param string $name
     * @return array
     */
    public function grantToken(User $user, bool $remember = false, string $name = 'Login', ?int $expirationMinutes = null): array;

    /**
     * Get active sessions for the authenticated user.
     */
    public function sessions(): array;

    /**
     * Revoke a specific session by token ID.
     */
    public function revokeSession(int|string $tokenId): bool;

    /**
     * Revoke all other sessions except the current one.
     */
    public function revokeOtherSessions(): int;

    /**
     * List API tokens (developer keys) for the authenticated user.
     *
     * @return array
     */
    public function apiTokens(): array;

    /**
     * Create a new API token (developer key) for the authenticated user.
     *
     * @param string $name
     * @param array $abilities
     * @return array
     */
    public function createApiToken(string $name, array $abilities = ['*']): array;

    /**
     * Revoke an API token by ID.
     *
     * @param int|string $tokenId
     * @return bool
     */
    public function revokeApiToken(int|string $tokenId): bool;

    /**
     * Refresh the current access token.
     *
     * @return array
     */
    public function refreshToken(): array;

    /**
     * Generate a short-lived QR login token for the given user.
     *
     * @param int $userId
     * @return array
     */
    public function generateQrToken(int $userId): array;

    /**
     * Exchange a QR login token for a full auth token (one-time use).
     *
     * @param string $token
     * @return array
     */
    public function qrLogin(string $token): array;

    /**
     * Report whether a QR login token is still pending, has been consumed
     * (successful login), or has expired. Used by the generating dialog to
     * auto-close once the user logs in.
     *
     * @param string $token
     * @return array{status: string}
     */
    public function qrTokenStatus(string $token): array;
}
