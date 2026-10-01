<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Models\OnlineMenu;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Http\Concerns\ResolvesAppCustomer;
use Modules\User\Models\CustomerAddress;
use Modules\User\Models\CustomerPushDevice;
use Modules\User\Models\User;
use Modules\User\Services\CustomerOtp\CustomerOtpService;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deliberately small authentication boundary for the consumer app.
 *
 * It never delegates to the staff login flow and it always derives tenant and
 * branch ownership from an active online menu resolved in the current tenant
 * context. This prevents a customer supplied tenant_id/branch_id from crossing
 * restaurant boundaries.
 */
class CustomerAuthController extends Controller
{
    use ResolvesAppCustomer;

    public function __construct(private readonly CustomerOtpService $otpService) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            // Channel remains accepted for older builds, but current customer
            // apps never expose delivery providers. The server selects an
            // enabled provider from restaurant settings.
            'channel' => ['nullable', 'string', 'in:whatsapp,email'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:20'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:160'],
            'installation_id' => ['required', 'uuid'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $this->guardCustomerApp();
        // The restaurant's customer-app policy chooses delivery. A browser
        // must not be able to override that policy by posting a channel value;
        // the legacy field remains accepted only for request compatibility.
        $channel = $this->otpService->selectChannel(
            (int) $menu->branch->tenant_id,
            $data['phone'] ?? null,
            $data['email'] ?? null,
        );

        return ApiResponse::success(
            $this->otpService->request($request, $menu, $channel, $data['phone'] ?? null, $data['email'] ?? null),
            message: 'If this account can receive messages, a verification code has been sent.',
        );
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'challenge_id' => ['required', 'uuid'],
            'otp' => ['required', 'digits:6'],
            'installation_id' => ['required', 'uuid'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'referral_code' => ['nullable', 'string', 'max:24'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $this->guardCustomerApp();
        $user = $this->otpService->verify($request, $menu, $data['challenge_id'], $data['otp'], $data);
        if (filled($data['referral_code'] ?? null)) {
            $code = DB::table('customer_referral_codes')->where('tenant_id', $user->tenant_id)
                ->where('code', Str::upper(trim($data['referral_code'])))->where('is_active', true)->first();
            $hasOrders = DB::table('orders')->where('customer_id', $user->id)->exists();
            if ($code && ! $hasOrders && (int) $code->referrer_customer_id !== (int) $user->id) {
                DB::table('customer_referrals')->insertOrIgnore([
                    'tenant_id' => $user->tenant_id, 'referral_code_id' => $code->id,
                    'referrer_customer_id' => $code->referrer_customer_id, 'referred_customer_id' => $user->id,
                    'status' => 'invited', 'reward_points' => $code->reward_points,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return ApiResponse::success($this->sessionPayload($user));
    }

    public function google(Request $request): JsonResponse
    {
        $this->guardCustomerApp();
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'id_token' => ['required', 'string', 'max:8192'],
            'installation_id' => ['required', 'uuid'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $tenantId = (int) $menu->branch->tenant_id;
        $settings = CustomerAppSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $clientId = trim((string) data_get($settings?->settings ?? [], 'google_server_client_id'));
        $enabled = (bool) data_get($settings?->settings ?? [], 'google_sign_in_enabled', false);
        abort_unless($enabled && $clientId !== '', Response::HTTP_FORBIDDEN, 'Google sign-in is not available for this restaurant.');

        $verified = Http::acceptJson()->timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['id_token'],
        ]);
        if (! $verified->successful()) {
            return ApiResponse::errors(['code' => 'INVALID_GOOGLE_ID_TOKEN'], 'Google sign-in could not be verified.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $identity = $verified->json();
        $issuer = (string) data_get($identity, 'iss');
        $expiresAt = (int) data_get($identity, 'exp', 0);
        $emailVerified = filter_var(data_get($identity, 'email_verified', false), FILTER_VALIDATE_BOOLEAN);
        $email = Str::lower(trim((string) data_get($identity, 'email')));
        if (! hash_equals($clientId, (string) data_get($identity, 'aud'))
            || ! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || $expiresAt <= now()->timestamp
            || ! $emailVerified
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || blank(data_get($identity, 'sub'))) {
            return ApiResponse::errors(['code' => 'INVALID_GOOGLE_ID_TOKEN'], 'Google sign-in could not be verified.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        if ($user && ! $user->roles()->where('name', DefaultRole::Customer->value)->exists()) {
            return ApiResponse::errors(['code' => 'CUSTOMER_ACCOUNT_CONFLICT'], 'This email cannot be used for customer sign-in.', Response::HTTP_CONFLICT);
        }
        if (! $user) {
            $user = User::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $menu->branch_id,
                'name' => trim((string) data_get($identity, 'name', 'Customer')) ?: 'Customer',
                'email' => $email,
                'email_verified_at' => now(),
                'phone' => null,
                'username' => 'customer_'.$tenantId.'_'.Str::lower(Str::random(12)),
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'can_login' => true,
            ]);
            $user->assignRole(DefaultRole::Customer->value);
        }
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        if (! $user->is_active || ! $user->can_login) {
            return ApiResponse::errors(['code' => 'CUSTOMER_ACCOUNT_DISABLED'], 'This customer account is disabled. Contact the restaurant.', Response::HTTP_FORBIDDEN);
        }
        $user->tokens()->where('name', 'customer-app')->delete();

        return ApiResponse::success($this->sessionPayload($user));
    }

    public function apple(Request $request): JsonResponse
    {
        $this->guardCustomerApp();
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'identity_token' => ['required', 'string', 'max:8192'],
            'installation_id' => ['required', 'uuid'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $tenantId = (int) $menu->branch->tenant_id;
        $settings = CustomerAppSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $clientId = trim((string) data_get($settings?->settings ?? [], 'apple_service_id'));
        $enabled = (bool) data_get($settings?->settings ?? [], 'apple_sign_in_enabled', false);
        abort_unless($enabled && $clientId !== '', Response::HTTP_FORBIDDEN, 'Apple sign-in is not available for this restaurant.');

        try {
            $keys = Cache::remember('customer-auth:apple:jwks', now()->addHours(6), function (): array {
                $response = Http::acceptJson()->timeout(10)->get('https://appleid.apple.com/auth/keys');
                $response->throw();

                return $response->json();
            });
            $identity = (array) JWT::decode($data['identity_token'], JWK::parseKeySet($keys));
        } catch (\Throwable) {
            return ApiResponse::errors(['code' => 'INVALID_APPLE_ID_TOKEN'], 'Apple sign-in could not be verified.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $audiences = array_map('strval', (array) ($identity['aud'] ?? []));
        $email = Str::lower(trim((string) ($identity['email'] ?? '')));
        if (($identity['iss'] ?? null) !== 'https://appleid.apple.com'
            || ! in_array($clientId, $audiences, true)
            || (int) ($identity['exp'] ?? 0) <= now()->timestamp
            || blank($identity['sub'] ?? null)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ApiResponse::errors(['code' => 'INVALID_APPLE_ID_TOKEN'], 'Apple sign-in could not be verified.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        if ($user && ! $user->roles()->where('name', DefaultRole::Customer->value)->exists()) {
            return ApiResponse::errors(['code' => 'CUSTOMER_ACCOUNT_CONFLICT'], 'This email cannot be used for customer sign-in.', Response::HTTP_CONFLICT);
        }
        if (! $user) {
            $user = User::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $menu->branch_id,
                'name' => trim((string) ($data['name'] ?? 'Customer')) ?: 'Customer',
                'email' => $email,
                'email_verified_at' => now(),
                'phone' => null,
                'username' => 'customer_'.$tenantId.'_'.Str::lower(Str::random(12)),
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'can_login' => true,
            ]);
            $user->assignRole(DefaultRole::Customer->value);
        }
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        if (! $user->is_active || ! $user->can_login) {
            return ApiResponse::errors(['code' => 'CUSTOMER_ACCOUNT_DISABLED'], 'This customer account is disabled. Contact the restaurant.', Response::HTTP_FORBIDDEN);
        }
        $user->tokens()->where('name', 'customer-app')->delete();

        return ApiResponse::success($this->sessionPayload($user));
    }

    public function requestPhoneChange(Request $request): JsonResponse
    {
        $user = $this->customerForRequest($request);
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:20'],
            'installation_id' => ['required', 'uuid'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);

        return ApiResponse::success($this->otpService->request($request, $menu, 'whatsapp', $data['phone'], null, 'change_phone', $user->id));
    }

    public function verifyPhoneChange(Request $request): JsonResponse
    {
        $current = $this->customerForRequest($request);
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'challenge_id' => ['required', 'uuid'],
            'otp' => ['required', 'digits:6'],
            'installation_id' => ['required', 'uuid'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $updated = $this->otpService->verify($request, $menu, $data['challenge_id'], $data['otp']);
        abort_unless($updated->is($current), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->customerPayload($updated->fresh()), message: 'Mobile number verified and updated.');
    }

    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $this->customerForRequest($request);
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:120'],
            'installation_id' => ['required', 'uuid'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);

        return ApiResponse::success($this->otpService->request($request, $menu, 'email', null, $data['email'], 'change_email', $user->id));
    }

    public function verifyEmailChange(Request $request): JsonResponse
    {
        $current = $this->customerForRequest($request);
        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'challenge_id' => ['required', 'uuid'],
            'otp' => ['required', 'digits:6'],
            'installation_id' => ['required', 'uuid'],
        ]);
        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $updated = $this->otpService->verify($request, $menu, $data['challenge_id'], $data['otp']);
        abort_unless($updated->is($current), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->customerPayload($updated->fresh()), message: 'Email address verified and updated.');
    }

    public function register(Request $request): JsonResponse
    {
        $this->guardCustomerApp();

        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'name' => ['required', 'string', 'max:120'],
            'gender' => ['required', Rule::enum(GenderType::class)],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9+() -]{7,20}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $phone = preg_replace('/\D+/', '', $data['phone']);
        $email = filled($data['email'] ?? null) ? Str::lower(trim($data['email'])) : null;

        $request->validate([
            'email' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) use ($email, $menu) {
                if ($email && User::withTrashed()->where('tenant_id', $menu->branch->tenant_id)->where('email', $email)->exists()) {
                    $fail('An account already uses this email address.');
                }
            }],
            'phone' => [function (string $attribute, mixed $value, \Closure $fail) use ($phone, $menu) {
                if (User::withTrashed()->where('tenant_id', $menu->branch->tenant_id)->where('phone', $phone)->exists()) {
                    $fail('An account already uses this phone number.');
                }
            }],
        ]);

        $user = User::query()->create([
            'tenant_id' => $menu->branch->tenant_id,
            'branch_id' => $menu->branch_id,
            'name' => trim($data['name']),
            'gender' => $data['gender'],
            'email' => $email,
            'phone' => $phone,
            'username' => 'customer_'.$menu->branch->tenant_id.'_'.Str::lower(Str::random(12)),
            'password' => Hash::make($data['password']),
            'is_active' => true,
            'can_login' => true,
        ]);
        $user->assignRole(DefaultRole::Customer->value);

        return ApiResponse::created($this->sessionPayload($user), message: 'Your customer account is ready.');
    }

    public function login(Request $request): JsonResponse
    {
        $this->guardCustomerApp();

        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'login' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $menu = $this->menuForRequest($request, $data['menu_slug']);
        $login = trim($data['login']);
        $normalizedPhone = preg_replace('/\D+/', '', $login);
        $key = 'customer-login:'.sha1($request->ip().'|'.$menu->branch->tenant_id.'|'.$login);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::errors(['code' => 'TOO_MANY_ATTEMPTS'], 'Too many attempts. Try again shortly.', 429);
        }

        $user = User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $menu->branch->tenant_id)
            ->whereHas('roles', fn ($query) => $query->where('name', DefaultRole::Customer->value))
            ->where(function ($query) use ($login, $normalizedPhone) {
                $query->where('email', Str::lower($login));
                if ($normalizedPhone !== '') {
                    $query->orWhere('phone', $normalizedPhone);
                }
            })
            ->first();

        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            RateLimiter::hit($key, 60);
            return ApiResponse::errors(['code' => 'INVALID_CUSTOMER_CREDENTIALS'], 'Email, phone, or password is incorrect.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $user->is_active || ! $user->can_login) {
            return ApiResponse::errors(['code' => 'CUSTOMER_ACCOUNT_DISABLED'], 'This customer account is disabled. Contact the restaurant.', Response::HTTP_FORBIDDEN);
        }

        RateLimiter::clear($key);
        $user->tokens()->where('name', 'customer-app')->delete();

        return ApiResponse::success($this->sessionPayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        $this->guardCustomerApp();

        $user = $this->customerForRequest($request);

        return ApiResponse::success($this->customerPayload($user));
    }

    public function update(Request $request): JsonResponse
    {
        $this->guardCustomerApp();

        $user = $this->customerForRequest($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'gender' => ['required', Rule::enum(GenderType::class)],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'phone' => [
                'nullable',
                'required_without:email',
                'string',
                'regex:/^[0-9+() -]{7,20}$/',
            ],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'anniversary_date' => ['nullable', 'date', 'before_or_equal:today'],
            'whatsapp_marketing_consent' => ['sometimes', 'boolean'],
        ]);

        $phone = filled($data['phone'] ?? null) ? preg_replace('/\D+/', '', $data['phone']) : null;
        $email = filled($data['email'] ?? null) ? Str::lower(trim($data['email'])) : null;

        // Profile updates may not silently replace an authenticated identity.
        // A different mobile must pass the dedicated OTP change flow.
        if ($phone !== ($user->phone ? preg_replace('/\D+/', '', $user->phone) : null)) {
            return ApiResponse::errors(
                ['phone' => ['Verify the new mobile number before saving it to your account.']],
                'Mobile verification is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($email !== ($user->email ? Str::lower(trim($user->email)) : null)) {
            return ApiResponse::errors(
                ['email' => ['Verify the new email address before saving it to your account.']],
                'Email verification is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $duplicateEmail = $email && User::query()
            ->withoutGlobalScopes()
            ->where($user->getKeyName(), '!=', $user->getKey())
            ->where('tenant_id', $user->tenant_id)
            ->where('email', $email)
            ->exists();
        if ($duplicateEmail) {
            return ApiResponse::errors(
                ['email' => ['An account already uses this email address.']],
                'Please check your details.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $profileUpdates = [
            'name' => trim($data['name']),
            'gender' => $data['gender'],
            'email' => $email,
            'phone' => $phone,
        ];
        foreach (['date_of_birth', 'anniversary_date'] as $optionalDate) {
            if (array_key_exists($optionalDate, $data)) {
                $profileUpdates[$optionalDate] = $data[$optionalDate];
            }
        }
        if (array_key_exists('whatsapp_marketing_consent', $data)) {
            if ($data['whatsapp_marketing_consent'] && ! $user->phone_verified_at) {
                return ApiResponse::errors(
                    ['whatsapp_marketing_consent' => ['Verify your mobile number before enabling WhatsApp messages.']],
                    'Mobile verification is required.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $profileUpdates['whatsapp_marketing_consent'] = (bool) $data['whatsapp_marketing_consent'];
            $profileUpdates['whatsapp_consent_source'] = 'customer_app_profile';
        }

        $user->forceFill($profileUpdates)->save();

        return ApiResponse::success($this->customerPayload($user->fresh()), message: 'Profile updated.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->customerForRequest($request);
        $installationId = $request->input('installation_id');

        if (is_string($installationId) && Str::isUuid($installationId)) {
            CustomerPushDevice::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('installation_id', $installationId)
                ->update(['revoked_at' => now()]);
        }

        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(message: 'Signed out successfully.');
    }

    private function guardCustomerApp(): void
    {
        abort_unless((bool) setting('customer_app_enabled', true), Response::HTTP_FORBIDDEN, 'The customer app is disabled for this restaurant.');
    }

    public function registerPushDevice(Request $request): JsonResponse
    {
        $user = $this->customerForRequest($request);
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'push_token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $token = trim($data['push_token']);
        $hash = hash('sha256', $token);

        $foreignToken = CustomerPushDevice::query()
            ->where('token_hash', $hash)
            ->where(function ($query) use ($user, $data): void {
                $query->where('tenant_id', '!=', $user->tenant_id)
                    ->orWhere('user_id', '!=', $user->id)
                    ->orWhere('installation_id', '!=', $data['installation_id']);
            })
            ->exists();
        if ($foreignToken) {
            return ApiResponse::errors(
                ['push_token' => ['This notification token is already assigned to another installation.']],
                'The notification device could not be registered.',
                Response::HTTP_CONFLICT,
            );
        }

        $device = CustomerPushDevice::query()->updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'installation_id' => $data['installation_id'],
            ],
            [
                'push_token' => $token,
                'token_hash' => $hash,
                'platform' => $data['platform'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        return ApiResponse::success([
            'registered' => true,
            'device_id' => $device->id,
        ], message: 'Customer notifications are enabled on this device.');
    }

    public function revokePushDevice(Request $request, string $installationId): JsonResponse
    {
        $user = $this->customerForRequest($request);
        abort_unless(Str::isUuid($installationId), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid installation reference.');

        CustomerPushDevice::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('installation_id', $installationId)
            ->update(['revoked_at' => now()]);

        return ApiResponse::success(message: 'Customer notifications are disabled on this device.');
    }

    public function addresses(Request $request): JsonResponse
    {
        $user = $this->customerForRequest($request);

        $addresses = CustomerAddress::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (CustomerAddress $address) => $this->addressPayload($address));

        return ApiResponse::success(['addresses' => $addresses]);
    }

    public function saveAddress(Request $request, ?string $reference = null): JsonResponse
    {
        $user = $this->customerForRequest($request);
        $data = $this->validateAddress($request);
        $clientReference = $reference ?: $data['id'];

        abort_unless(Str::isUuid($clientReference), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid address reference.');

        $address = CustomerAddress::query()->updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'client_reference' => $clientReference,
            ],
            [
                'label' => trim($data['label'] ?? 'Home'),
                'recipient_name' => trim($data['recipient_name']),
                'phone' => preg_replace('/\D+/', '', $data['phone']),
                'address_line1' => trim($data['address_line1']),
                'address_line2' => filled($data['address_line2'] ?? null) ? trim($data['address_line2']) : null,
                'landmark' => filled($data['landmark'] ?? null) ? trim($data['landmark']) : null,
                'city' => trim($data['city']),
                'postal_code' => trim($data['postal_code']),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ],
        );

        return ApiResponse::success($this->addressPayload($address), message: 'Delivery address saved.');
    }

    public function deleteAddress(Request $request, string $reference): JsonResponse
    {
        $user = $this->customerForRequest($request);

        CustomerAddress::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('client_reference', $reference)
            ->delete();

        return ApiResponse::success(message: 'Delivery address removed.');
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'id' => ['required', 'uuid'],
            'label' => ['nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9+() -]{7,20}$/'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    private function addressPayload(CustomerAddress $address): array
    {
        return [
            'id' => $address->client_reference,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
            'landmark' => $address->landmark,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ];
    }

    private function menuForRequest(Request $request, string $slug): OnlineMenu
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $query = OnlineMenu::query()
            ->withoutGlobalScopes()
            ->with('branch')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('is_active', true))
            ->when($tenantId > 0, fn ($query) => $query->whereHas('branch', fn ($branchQuery) => $branchQuery
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)));

        $menu = $query->firstOrFail();
        $resolvedTenantId = (int) $menu->branch->tenant_id;

        // Public QR authentication resolves ownership from the menu instead of
        // a signed customer-app token. Bind that authoritative tenant before
        // reading provider/settings data; otherwise the request can retain the
        // global settings singleton and incorrectly route OTP through the
        // platform default provider (historically MSG91).
        app(TenantContext::class)->setId($resolvedTenantId);
        $request->attributes->set('tenant_id', $resolvedTenantId);
        $request->attributes->set('branch_id', (int) $menu->branch_id);
        app(SettingServiceInterface::class)->refreshSettingBinding();

        return $menu;
    }

    private function sessionPayload(User $user): array
    {
        return [
            'token' => $user->createToken('customer-app', ['customer'])->plainTextToken,
            'customer' => $this->customerPayload($user),
        ];
    }

    private function customerPayload(User $user): array
    {
        $tenantReference = Tenant::query()->withoutGlobalScopes()
            ->whereKey($user->tenant_id)
            ->value('uuid');
        $branchReference = $user->branch_id
            ? \Modules\Branch\Models\Branch::query()->withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->whereKey($user->branch_id)
                ->value('uuid')
            : null;

        return [
            'reference' => $user->uuid,
            'tenant_reference' => $tenantReference,
            'branch_reference' => $branchReference,
            // Deprecated compatibility fields. Authorization never trusts
            // these values; clients should migrate to the opaque references.
            'id' => $user->id,
            'name' => $user->name,
            'gender' => $user->gender?->value,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified' => (bool) $user->email_verified_at,
            'phone_verified' => (bool) $user->phone_verified_at,
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'anniversary_date' => $user->anniversary_date?->toDateString(),
            'whatsapp_marketing_consent' => (bool) $user->whatsapp_marketing_consent,
            'whatsapp_marketing_consented_at' => $user->whatsapp_marketing_consented_at?->toIso8601String(),
            'whatsapp_opted_out_at' => $user->whatsapp_opted_out_at?->toIso8601String(),
            'profile_complete' => filled($user->name)
                && strcasecmp(trim((string) $user->name), 'Guest') !== 0
                && $user->gender !== null,
        ];
    }

}
