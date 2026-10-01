<?php

namespace Modules\User\Services\Auth;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\PersonalAccessToken;
use Modules\User\Models\QrLoginToken;
use Modules\User\Models\User;
use Modules\User\Enums\DefaultRole;
use Modules\User\Services\Mfa\TotpService;
use Modules\Saas\Support\TenantContext;

class AuthService implements AuthServiceInterface
{
    private ?array $personalAccessTokenColumns = null;

    public function __construct(
        private readonly TotpService $totpService,
        private readonly TenantContext $tenantContext,
    )
    {
    }

    /** @inheritDoc */
    public function login(array $credentials): array
    {
        $isEmail = $this->isEmail($credentials['identifier']);

        $user = User::when($isEmail, fn(Builder $query) => $query->where("email", $credentials["identifier"]))
            ->when(!$isEmail, fn(Builder $query) => $query->where("username", $credentials["identifier"]))
            ->when($this->tenantContext->hasTenant(), fn(Builder $query) => $query->where('tenant_id', $this->tenantContext->id()))
            ->with('tenant:id,is_active,name')
            ->withTrashed()
            ->withoutGlobalActive()
            ->first();
        $this->validateUserStatus($user);

        abort_if(!Hash::check($credentials['password'], $user->password), 401, __('auth.failed'));

        if ((bool) config('saas.security.require_platform_mfa', false) && ! $user->tenant_id && ! $user->mfa_enabled) {
            throw ValidationException::withMessages([
                'identifier' => 'MFA is required for platform administrators. Ask a security administrator to complete MFA enrollment before enforcing this policy.',
            ]);
        }
        if (
            (bool) config('saas.security.require_tenant_admin_mfa', false)
            && $user->tenant_id
            && $user->hasAnyRole([DefaultRole::EnterpriseAdmin->value, DefaultRole::AdminBranch->value])
            && ! $user->mfa_enabled
        ) {
            throw ValidationException::withMessages([
                'identifier' => 'Two-factor authentication is required for restaurant administrators. Enrol MFA from My Account before enabling enforcement.',
            ]);
        }

        if ($user->mfa_enabled) {
            return $this->createMfaChallenge($user);
        }

        return $this->grantToken($user, (bool) ($credentials['remember'] ?? false));
    }

    public function verifyMfaLogin(array $data): array
    {
        $challengeKey = $this->mfaChallengeKey($data['challenge_token']);
        $userId = Cache::get($challengeKey);
        $user = User::query()->withoutGlobalActive()->find($userId);
        $this->validateUserStatus($user);

        $code = trim($data['code']);
        $recoveryCodes = $user->mfa_recovery_codes ?? [];
        $matchingRecoveryCode = collect($recoveryCodes)
            ->first(fn(string $recoveryCode) => hash_equals($recoveryCode, strtoupper($code)));

        $validTotp = filled($user->mfa_secret) && $this->totpService->verify($user->mfa_secret, $code);
        if (!$validTotp && blank($matchingRecoveryCode)) {
            throw ValidationException::withMessages(['code' => __('user::messages.mfa_code_invalid')]);
        }

        if (filled($matchingRecoveryCode)) {
            $user->forceFill([
                'mfa_recovery_codes' => collect($recoveryCodes)
                    ->reject(fn(string $recoveryCode) => hash_equals($recoveryCode, $matchingRecoveryCode))
                    ->values()
                    ->all(),
            ])->save();
        }

        Cache::forget($challengeKey);

        return $this->grantToken($user);
    }

    /** @inheritDoc */
    public function isEmail(string $identifier): bool
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL);
    }

    /** @inheritDoc */
    public function validateUserStatus(?User $user): void
    {
        abort_if(!$user, 401, __('auth.failed'));
        abort_if($user->trashed(), 401, __('user::messages.account_deleted'));
        // Staff recorded for scheduling and attendance only. Even if a password
        // is ever set on the record, it must not be usable to sign in.
        abort_if(!$user->can_login, 401, __('user::messages.account_not_a_login'));
        abort_if(!$user->is_active, 401, __('user::messages.account_not_activated'));
        abort_if($user->tenant_id && $user->tenant && ! $user->tenant->is_active, 403, __('user::messages.tenant_suspended'));
    }

    /** @inheritDoc */
    public function grantToken(User $user, bool $remember = false, string $name = 'Login', ?int $expirationMinutes = null): array
    {
        $tokenExpirationMinutes = $expirationMinutes ?? (int) config('sanctum.expiration', 1440);
        $tokenExpirationMinutes = $tokenExpirationMinutes > 0 ? $tokenExpirationMinutes : 1440;

        $expiration = $expirationMinutes !== null
            ? now()->addMinutes($tokenExpirationMinutes)
            : ($remember
            ? now()->addDays(($tokenExpirationMinutes / 1440) * 30) // 30 days for remember me
            : now()->addMinutes($tokenExpirationMinutes)); // Default 24 hours

        $tokenResult = $user->createToken($name, ['*'], $expiration);
        $token = $tokenResult->plainTextToken;

        $accessToken = $tokenResult->accessToken;
        $metadata = $this->tokenMetadata($name);

        if (!empty($metadata)) {
            $accessToken->forceFill($metadata)->save();
        }

        event(new Login("api", $user, $remember));

        return [
            'user' => $user,
            'token' => $token,
            'expires_at' => $expiration->toIso8601String(),
        ];
    }

    private function createMfaChallenge(User $user): array
    {
        $token = Str::random(64);
        Cache::put($this->mfaChallengeKey($token), $user->id, now()->addMinutes(5));

        return ['mfa_required' => true, 'challenge_token' => $token];
    }

    private function mfaChallengeKey(string $token): string
    {
        return "auth:mfa:{$token}";
    }

    /** @inheritDoc */
    public function logout(): bool
    {
        $user = Auth::user();

        /** @var PersonalAccessToken $currentAccessToken */
        $currentAccessToken = $user->currentAccessToken();
        $logout = $currentAccessToken?->delete() ?: false;

        event(new Logout("api", $user));

        return $logout;
    }

    /**
     * Get active sessions for the authenticated user.
     */
    public function sessions(): array
    {
        $user = Auth::user();

        return $user->tokens()
            ->select($this->sessionColumns())
            ->orderBy('last_used_at', 'desc')
            ->get()
            ->map(fn(PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'device_name' => $this->tokenAttribute($token, 'device_name'),
                'ip_address' => $this->tokenAttribute($token, 'ip_address'),
                'user_agent' => $this->tokenAttribute($token, 'user_agent'),
                'last_active_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'is_current' => $token->id === $user->currentAccessToken()?->id,
            ])
            ->all();
    }

    /**
     * Revoke a specific session by token ID.
     */
    public function revokeSession(int|string $tokenId): bool
    {
        $user = Auth::user();

        $token = $user->tokens()->find($tokenId);

        abort_if(!$token, 404, __('user::messages.session_not_found'));

        return $token->delete();
    }

    /**
     * Revoke all other sessions except the current one.
     */
    public function revokeOtherSessions(): int
    {
        $user = Auth::user();
        $currentTokenId = $user->currentAccessToken()?->id;

        return $user->tokens()
            ->when($currentTokenId, fn($query) => $query->where('id', '!=', $currentTokenId))
            ->delete();
    }

    /** @inheritDoc */
    public function apiTokens(): array
    {
        $user = Auth::user();

        return $user->tokens()
            ->where('name', 'like', 'api-%')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
            ])
            ->all();
    }

    /** @inheritDoc */
    public function createApiToken(string $name, array $abilities = ['*']): array
    {
        $user = Auth::user();
        $tokenName = 'api-' . $name;

        // API tokens have longer expiration (1 year by default)
        $expiration = now()->addYear();

        $tokenResult = $user->createToken($tokenName, $abilities, $expiration);
        $plainToken = $tokenResult->plainTextToken;

        $metadata = $this->tokenMetadata($name);

        if (!empty($metadata)) {
            $tokenResult->accessToken->forceFill($metadata)->save();
        }

        return [
            'token' => $plainToken,
            'name' => $tokenName,
            'abilities' => $abilities,
            'expires_at' => $expiration->toIso8601String(),
        ];
    }

    /** @inheritDoc */
    public function revokeApiToken(int|string $tokenId): bool
    {
        $user = Auth::user();

        $token = $user->tokens()
            ->where('name', 'like', 'api-%')
            ->find($tokenId);

        abort_if(!$token, 404, __('user::messages.token_not_found'));

        return $token->delete();
    }

    /** @inheritDoc */
    public function generateQrToken(int $userId): array
    {
        User::query()->withoutGlobalActive()->findOrFail($userId);

        $token = Str::uuid()->toString();
        $ttl = now()->addMinutes(5);

        QrLoginToken::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        QrLoginToken::query()->create([
            'user_id' => $userId,
            'token_hash' => $this->qrTokenHash($token),
            'expires_at' => $ttl,
        ]);

        return [
            'token' => $token,
            'expires_at' => $ttl->toIso8601String(),
            'expires_in_seconds' => 300,
        ];
    }

    /** @inheritDoc */
    public function qrLogin(string $token): array
    {
        return DB::transaction(function () use ($token): array {
            $qrToken = QrLoginToken::query()
                ->where('token_hash', $this->qrTokenHash($token))
                ->lockForUpdate()
                ->first();

            abort_if(
                !$qrToken || $qrToken->consumed_at || $qrToken->expires_at->isPast(),
                401,
                __('user::messages.qr_token_invalid')
            );

            $user = User::query()->withoutGlobalActive()->find($qrToken->user_id);
            abort_if(!$user, 401, __('user::messages.qr_token_invalid'));
            abort_if(
                $this->tenantContext->hasTenant()
                && !$user->isSuperAdmin()
                && (int) $user->tenant_id !== (int) $this->tenantContext->id(),
                401,
                __('user::messages.qr_token_invalid')
            );

            $this->validateUserStatus($user);
            $result = $this->grantToken($user, false, 'QR Login');

            $qrToken->forceFill(['consumed_at' => now()])->save();

            return $result;
        }, 3);
    }

    /** @inheritDoc */
    public function qrTokenStatus(string $token): array
    {
        $qrToken = QrLoginToken::query()
            ->where('token_hash', $this->qrTokenHash($token))
            ->first();

        if ($qrToken?->consumed_at) {
            return ['status' => 'consumed'];
        }

        if (!$qrToken || $qrToken->expires_at->isPast()) {
            return ['status' => 'expired'];
        }

        return ['status' => 'pending'];
    }

    private function qrTokenHash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /** @inheritDoc */
    public function refreshToken(): array
    {
        $user = Auth::user();
        $currentToken = $user->currentAccessToken();

        abort_if(!$currentToken, 401, __('auth.failed'));

        // Delete current token
        $currentToken->delete();

        // Create new token with same expiration as original
        $originalExpiration = $currentToken->expires_at;
        $wasRemembered = $originalExpiration && $originalExpiration->diffInDays(now()) > 1;

        return $this->grantToken($user, $wasRemembered, $currentToken->name);
    }

    private function tokenMetadata(string $deviceName): array
    {
        $metadata = [];
        $columns = $this->personalAccessTokenColumns();

        if (in_array('ip_address', $columns, true)) {
            $metadata['ip_address'] = request()->ip();
        }

        if (in_array('user_agent', $columns, true)) {
            $metadata['user_agent'] = request()->userAgent();
        }

        if (in_array('device_name', $columns, true)) {
            $metadata['device_name'] = $deviceName;
        }

        return $metadata;
    }

    private function sessionColumns(): array
    {
        $columns = ['id', 'name', 'last_used_at', 'expires_at', 'created_at'];

        $availableColumns = $this->personalAccessTokenColumns();
        foreach (['ip_address', 'user_agent', 'device_name'] as $column) {
            if (in_array($column, $availableColumns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function personalAccessTokenColumns(): array
    {
        return $this->personalAccessTokenColumns
            ??= Schema::getColumnListing('personal_access_tokens');
    }

    private function tokenAttribute(PersonalAccessToken $token, string $attribute): mixed
    {
        return array_key_exists($attribute, $token->getAttributes())
            ? $token->getAttribute($attribute)
            : null;
    }
}
