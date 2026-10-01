<?php

namespace Modules\User\Services\CustomerOtp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Menu\Models\OnlineMenu;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\CustomerOtpChallenge;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class CustomerOtpService
{
    public function __construct(private readonly CustomerOtpSender $sender) {}

    /**
     * Select delivery privately so consumer clients do not learn which
     * notification provider or template a restaurant uses.
     */
    public function selectChannel(int $tenantId, ?string $phone, ?string $email): string
    {
        $allowed = $this->allowedChannels($tenantId);

        if (filled($phone) && in_array('whatsapp', $allowed, true)) {
            return 'whatsapp';
        }

        if (filled($email) && in_array('email', $allowed, true)) {
            return 'email';
        }

        throw ValidationException::withMessages([
            'login' => ['Verification is temporarily unavailable. Please contact the restaurant.'],
        ]);
    }

    public function request(Request $request, OnlineMenu $menu, string $channel, ?string $rawPhone, ?string $rawEmail, string $purpose = 'login', ?int $userId = null): array
    {
        abort_unless(in_array($channel, $this->allowedChannels((int) $menu->branch->tenant_id), true), Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected verification channel is unavailable.');
        $phone = $channel === 'whatsapp' ? $this->normalizePhone((string) $rawPhone) : null;
        $email = $channel === 'email' ? Str::lower(trim((string) $rawEmail)) : null;
        if ($channel === 'email' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['Enter a valid email address.']]);
        }
        $recipient = $phone ?: $email;
        $tenantId = (int) $menu->branch->tenant_id;
        $installation = trim((string) $request->input('installation_id'));
        abort_unless(Str::isUuid($installation), Response::HTTP_UNPROCESSABLE_ENTITY, 'A valid app installation is required.');

        $rateKey = 'customer-otp-send:'.hash('sha256', implode('|', [$tenantId, $channel, $recipient, $installation, $request->ip()]));
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Please wait before requesting another code.');
        }

        $resendSeconds = max(30, (int) config('services.customer_otp.resend_seconds', 60));
        $recent = CustomerOtpChallenge::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where($channel === 'email' ? 'email' : 'phone', $recipient)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();
        if ($recent?->sent_at?->gt(now()->subSeconds($resendSeconds))) {
            return ['challenge_id' => $recent->reference, 'expires_at' => $recent->expires_at->toIso8601String(), 'resend_after_seconds' => $recent->sent_at->addSeconds($resendSeconds)->diffInSeconds(now())];
        }

        $ttl = max(2, min(15, (int) config('services.customer_otp.ttl_minutes', 5)));
        $reference = (string) Str::uuid();
        $testCode = $this->testCodeFor($recipient);
        $otp = $testCode ?? (string) random_int(100000, 999999);
        $challenge = CustomerOtpChallenge::query()->create([
            'reference' => $reference,
            'tenant_id' => $tenantId,
            'branch_id' => $menu->branch_id,
            'user_id' => $userId,
            'phone' => $phone,
            'email' => $email,
            'channel' => $channel,
            'purpose' => $purpose,
            'otp_hash' => $this->hashOtp($reference, $otp),
            'installation_hash' => hash('sha256', $installation),
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'max_attempts' => max(3, min(10, (int) config('services.customer_otp.max_attempts', 5))),
            'sent_at' => now(),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        if ($testCode !== null) {
            // A local/testing challenge has no external delivery side effect.
            $challenge->forceFill(['provider_reference' => 'local-test-otp'])->save();
        } else {
            try {
                $challenge->provider_reference = $this->sender->send(
                    $channel,
                    $recipient,
                    $otp,
                    $ttl,
                    $tenantId,
                    (string) ($menu->name ?: $menu->branch?->name ?: config('app.name')),
                );
                $challenge->save();
            } catch (\Throwable $exception) {
                $challenge->delete();
                report($exception);
                abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Verification service is temporarily unavailable.');
            }
        }
        RateLimiter::hit($rateKey, 600);

        return ['challenge_id' => $reference, 'expires_at' => $challenge->expires_at->toIso8601String(), 'resend_after_seconds' => $resendSeconds];
    }

    public function verify(Request $request, OnlineMenu $menu, string $reference, string $otp, array $profile = []): User
    {
        $installation = trim((string) $request->input('installation_id'));
        abort_unless(Str::isUuid($installation), Response::HTTP_UNPROCESSABLE_ENTITY, 'A valid app installation is required.');

        $user = DB::transaction(function () use ($request, $menu, $reference, $otp, $profile, $installation): ?User {
            $challenge = CustomerOtpChallenge::query()->lockForUpdate()->where('reference', $reference)->first();
            $validBoundary = $challenge
                && (int) $challenge->tenant_id === (int) $menu->branch->tenant_id
                && hash_equals($challenge->installation_hash, hash('sha256', $installation))
                && hash_equals($challenge->ip_hash, hash('sha256', (string) $request->ip()));
            if (! $validBoundary || $challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= $challenge->max_attempts) {
                throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
            }

            $challenge->increment('attempts');
            if (! hash_equals($challenge->otp_hash, $this->hashOtp($reference, $otp))) {
                return null;
            }

            $challenge->forceFill(['consumed_at' => now()])->save();
            if (in_array($challenge->purpose, ['change_phone', 'change_email'], true)) {
                $user = User::query()->withoutGlobalScopes()->whereKey($challenge->user_id)->where('tenant_id', $challenge->tenant_id)->firstOrFail();
                $column = $challenge->channel === 'email' ? 'email' : 'phone';
                $identity = $challenge->{$column};
                $ownedByAnotherCustomer = User::query()->withoutGlobalScopes()
                    ->where('tenant_id', $challenge->tenant_id)
                    ->where($user->getKeyName(), '!=', $user->getKey())
                    ->where($column, $identity)
                    ->whereHas('roles', fn ($query) => $query->where('name', DefaultRole::Customer->value))
                    ->exists();
                if ($ownedByAnotherCustomer) {
                    throw ValidationException::withMessages([$column => ['This identity is already linked to another customer account.']]);
                }
                $user->forceFill([
                    $column => $identity,
                    $column.'_verified_at' => now(),
                ])->save();
                return $user;
            }

            $user = User::query()->withoutGlobalScopes()
                ->where('tenant_id', $challenge->tenant_id)
                ->where($challenge->channel === 'email' ? 'email' : 'phone', $challenge->channel === 'email' ? $challenge->email : $challenge->phone)
                ->whereHas('roles', fn ($query) => $query->where('name', DefaultRole::Customer->value))
                ->first();
            if (! $user) {
                $user = User::query()->create([
                    'tenant_id' => $challenge->tenant_id,
                    'branch_id' => $challenge->branch_id,
                    'name' => trim((string) ($profile['name'] ?? 'Guest')) ?: 'Guest',
                    // Only persist the identity proven by this challenge. A
                    // phone supplied alongside an email OTP (or vice versa)
                    // must complete its own verification before account link.
                    'email' => $challenge->email,
                    'phone' => $challenge->phone,
                    'email_verified_at' => $challenge->email ? now() : null,
                    'phone_verified_at' => $challenge->phone ? now() : null,
                    // Invoice buyers require an ISO country. Persist the
                    // restaurant's configured default when an OTP creates a
                    // phone customer, instead of letting a later POS payment
                    // fail while generating the invoice party.
                    'phone_country_iso_code' => $challenge->phone
                        ? (setting('default_country_iso_code') ?: 'IN')
                        : null,
                    'username' => 'customer_'.$challenge->tenant_id.'_'.Str::lower(Str::random(12)),
                    'password' => bcrypt(Str::random(48)),
                    'is_active' => true,
                    'can_login' => true,
                ]);
                $user->assignRole(DefaultRole::Customer->value);
            }

            $verifiedColumn = $challenge->channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
            if (! $user->{$verifiedColumn}) {
                $user->forceFill([$verifiedColumn => now()])->save();
            }

            abort_unless($user->is_active && $user->can_login, Response::HTTP_FORBIDDEN, 'This customer account is disabled.');
            $user->tokens()->where('name', 'customer-app')->delete();
            return $user;
        }, 3);

        if (! $user) {
            throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
        }

        return $user;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 10) $digits = '91'.$digits;
        if (! preg_match('/^[1-9][0-9]{9,14}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid mobile number with country code.']]);
        }
        return $digits;
    }

    private function allowedChannels(int $tenantId): array
    {
        $customerAppSettings = CustomerAppSetting::query()->where('tenant_id', $tenantId)->first();
        $configured = data_get($customerAppSettings?->settings ?? [], 'otp_channels');
        $channels = is_array($configured) ? $configured : config('services.customer_otp.channels', ['whatsapp', 'email']);

        $allowed = array_values(array_intersect(['whatsapp', 'email'], $channels));
        $emailTemplate = data_get($customerAppSettings?->settings ?? [], 'email_templates.customer_otp');
        if (is_array($emailTemplate) && array_key_exists('is_active', $emailTemplate) && ! $emailTemplate['is_active']) {
            $allowed = array_values(array_diff($allowed, ['email']));
        }

        return $allowed;
    }

    private function hashOtp(string $reference, string $otp): string
    {
        return hash_hmac('sha256', $reference.'|'.$otp, (string) config('app.key'));
    }

    /**
     * A deterministic code is useful for automated acceptance tests, but it
     * must never become an authentication backdoor. Production requires an
     * explicit enable flag and an unexpired cutoff. It is always limited to an
     * allow-list of QA recipients, so a loose environment file cannot affect
     * every customer.
     */
    private function testCodeFor(string $recipient): ?string
    {
        $code = trim((string) config('services.customer_otp.test_code'));
        $recipients = array_map('strtolower', (array) config('services.customer_otp.test_recipients', []));

        if (! preg_match('/^\\d{6}$/', $code) || ! in_array(Str::lower($recipient), $recipients, true)) {
            return null;
        }

        if (app()->environment('production')) {
            $expiresAt = config('services.customer_otp.test_expires_at');
            if (! config('services.customer_otp.test_enabled') || blank($expiresAt)) {
                return null;
            }

            try {
                if (Carbon::parse($expiresAt)->isPast()) {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return $code;
    }
}
