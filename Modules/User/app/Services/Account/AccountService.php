<?php

namespace Modules\User\Services\Account;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\User;
use Modules\User\Services\Mfa\TotpService;

class AccountService implements AccountServiceInterface
{
    public function __construct(protected TotpService $totpService) {}

    /** {@inheritDoc} */
    public function me(): User
    {
        return $this->getModel()
            ->with(['branch'])
            ->where('id', auth()->id())
            ->first();
    }

    /** {@inheritDoc} */
    public function getModel(): User
    {
        return new ($this->model());
    }

    /** {@inheritDoc} */
    public function model(): string
    {
        return User::class;
    }

    /** {@inheritDoc} */
    public function updateProfile(array $data): User
    {
        auth()->user()->update($data);

        return auth()->user()->fresh();
    }

    /** {@inheritDoc} */
    public function updatePassword(array $data): bool
    {
        $user = auth()->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('user::messages.current_password_incorrect'),
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('user::messages.new_password_same_current_password'),
            ]);
        }

        $isUpdated = $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        if ($isUpdated && isset($data['logout_from_other_devices']) && $data['logout_from_other_devices']) {
            $this->logoutFromOtherDevices($user);
        }

        return $isUpdated;
    }

    /** {@inheritDoc} */
    public function logoutFromOtherDevices(User $user): void
    {
        $user->tokens()->where('id', '!=', optional($user->currentAccessToken())->id)->delete();
    }

    public function beginMfaSetup(): array
    {
        $user = auth()->user();
        $secret = $this->totpService->generateSecret();

        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'provisioning_uri' => $this->totpService->provisioningUri($secret, $user->email, config('app.name', 'NexDine')),
        ];
    }

    public function confirmMfaSetup(string $code): array
    {
        $user = auth()->user();

        if (blank($user->mfa_secret) || ! $this->totpService->verify($user->mfa_secret, $code)) {
            throw ValidationException::withMessages(['code' => __('user::messages.mfa_code_invalid')]);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => strtoupper(bin2hex(random_bytes(5))))
            ->all();

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_recovery_codes' => $recoveryCodes,
            'mfa_confirmed_at' => now(),
        ])->save();

        return ['recovery_codes' => $recoveryCodes];
    }

    public function disableMfa(): bool
    {
        return auth()->user()->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
        ])->save();
    }
}
