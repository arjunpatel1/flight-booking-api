<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Services\Auth\AuthServiceInterface;
use Modules\User\Transformers\Api\V1\AuthResource;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Passkey (WebAuthn) endpoints tailored to this token-based API. The stock
 * laravel/passkeys routes require a stateful guard, so these custom controllers
 * wrap the package's vetted WebAuthn actions, store the ceremony challenge in
 * the cache (keyed by user id / a random handle) instead of the session, and
 * issue a Sanctum token on login.
 *
 * NOTE: the register/login ceremonies require a real browser (navigator.
 * credentials) and could not be exercised end-to-end in this environment — they
 * must be validated live before being trusted.
 */
class PasskeyController extends Controller
{
    /** Challenge lifetime, seconds. */
    private const CHALLENGE_TTL = 300;

    public function __construct(protected AuthServiceInterface $auth)
    {
    }

    // --- Management (auth:sanctum) ---

    /** Options for registering a new passkey for the current user. */
    public function registrationOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $user = $request->user();
        $options = $generate($user);

        Cache::put($this->registerKey($user->getKey()), WebAuthn::toJson($options), self::CHALLENGE_TTL);

        return ApiResponse::success(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /** Verify + store a newly created passkey. */
    public function store(Request $request, StorePasskey $store): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'credential' => ['required', 'array'],
        ]);

        $user = $request->user();
        $serialized = Cache::pull($this->registerKey($user->getKey()));

        if (! $serialized) {
            throw ValidationException::withMessages(['credential' => __('user::auth.passkey_expired')]);
        }

        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialCreationOptions::class);
        $passkey = $store($user, $data['name'], $this->parseCredential($data['credential']), $options);

        return ApiResponse::success(['passkey' => $this->passkeyResource($passkey)]);
    }

    /** List the current user's passkeys. */
    public function index(Request $request): JsonResponse
    {
        $passkeys = Passkey::query()
            ->where('user_id', $request->user()->getKey())
            ->latest()
            ->get()
            ->map(fn (Passkey $passkey) => $this->passkeyResource($passkey));

        return ApiResponse::success(['data' => $passkeys]);
    }

    /** Remove one of the current user's passkeys. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        Passkey::query()
            ->where('user_id', $request->user()->getKey())
            ->whereKey($id)
            ->delete();

        return ApiResponse::success(['message' => __('user::auth.passkey_removed')]);
    }

    // --- Login (guest) ---

    /** Options for a usernameless passkey login; returns a handle to correlate verify. */
    public function loginOptions(GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        $handle = Str::random(40);

        Cache::put($this->loginKey($handle), WebAuthn::toJson($options), self::CHALLENGE_TTL);

        return ApiResponse::success([
            'handle' => $handle,
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /** Verify a passkey assertion and issue a Sanctum token. */
    public function loginVerify(Request $request, VerifyPasskey $verify): JsonResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string'],
            'credential' => ['required', 'array'],
        ]);

        $serialized = Cache::pull($this->loginKey($data['handle']));

        if (! $serialized) {
            throw ValidationException::withMessages(['credential' => __('user::auth.passkey_expired')]);
        }

        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class);
        $passkey = $verify($this->parseCredential($data['credential']), $options);

        $user = $passkey->user;
        abort_if($user->trashed(), 401, __('user::messages.account_deleted'));
        abort_if(! $user->is_active, 401, __('user::messages.account_not_activated'));

        $result = $this->auth->grantToken($user, false, 'Passkey');

        return ApiResponse::success([
            'user' => new AuthResource($result['user']),
            'token' => $result['token'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    // --- Helpers ---

    private function parseCredential(array $credential): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(json_encode($credential) ?: '{}', PublicKeyCredential::class);
        } catch (Throwable) {
            throw ValidationException::withMessages(['credential' => __('user::auth.passkey_invalid')]);
        }
    }

    private function passkeyResource(Passkey $passkey): array
    {
        return [
            'id' => $passkey->getKey(),
            'name' => $passkey->name,
            'last_used_at' => optional($passkey->last_used_at)->toIso8601String(),
            'created_at' => optional($passkey->created_at)->toIso8601String(),
        ];
    }

    private function registerKey(int|string $userId): string
    {
        return "passkey_reg:{$userId}";
    }

    private function loginKey(string $handle): string
    {
        return "passkey_login:{$handle}";
    }
}
