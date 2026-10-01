<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\ForgotPasswordRequest;
use Modules\User\Http\Requests\Api\V1\LoginRequest;
use Modules\User\Http\Requests\Api\V1\ResetPasswordRequest;
use Modules\User\Http\Requests\Api\V1\VerifyMfaLoginRequest;
use Modules\User\Services\Auth\AuthServiceInterface;
use Modules\User\Transformers\Api\V1\AuthResource;

class AuthController extends Controller
{
    /**
     * Create a new instance of AuthController
     *
     * @param AuthServiceInterface $service
     */
    public function __construct(protected AuthServiceInterface $service)
    {
    }

    /**
     * User store authentication
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $this->service->login($request->validated());

        if ($data['mfa_required'] ?? false) {
            return ApiResponse::success($data);
        }

        return ApiResponse::success([
            "user" => new AuthResource($data['user']),
            "token" => $data['token'],
            "expires_at" => $data['expires_at'] ?? null,
        ]);
    }

    public function verifyMfa(VerifyMfaLoginRequest $request): JsonResponse
    {
        $data = $this->service->verifyMfaLogin($request->validated());

        return ApiResponse::success([
            "user" => new AuthResource($data['user']),
            "token" => $data['token'],
            "expires_at" => $data['expires_at'] ?? null,
        ]);
    }

    /**
     * Email a password reset link. Always returns a generic success message so
     * the endpoint cannot be used to enumerate registered emails.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return ApiResponse::success([
            'message' => __('passwords.sent'),
        ]);
    }

    /**
     * Reset the password using a valid token from the emailed link.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return ApiResponse::success([
            'message' => __($status),
        ]);
    }

    /**
     * Destroy user authentication
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        return ApiResponse::success(
            body: ["success" => $this->service->logout()],
            message: __('auth.logout')
        );
    }

    /**
     * This API helps the frontend guarantee that the token is valid and return user data.
     *
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        return ApiResponse::success(['user' => new AuthResource(auth()->user())]);
    }

    /**
     * List active user sessions.
     */
    public function sessions(): JsonResponse
    {
        return ApiResponse::success($this->service->sessions());
    }

    /**
     * Revoke a specific session.
     */
    public function revokeSession(int|string $tokenId): JsonResponse
    {
        return ApiResponse::success(
            body: ['revoked' => $this->service->revokeSession($tokenId)],
            message: __('user::messages.session_revoked')
        );
    }

    /**
     * Revoke all other sessions except current.
     */
    public function revokeOtherSessions(): JsonResponse
    {
        return ApiResponse::success(
            body: ['revoked_count' => $this->service->revokeOtherSessions()],
            message: __('user::messages.other_sessions_revoked')
        );
    }

    /**
     * List API tokens (developer keys).
     */
    public function apiTokens(): JsonResponse
    {
        return ApiResponse::success($this->service->apiTokens());
    }

    /**
     * Create a new API token (developer key).
     */
    public function createApiToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
        ]);

        return ApiResponse::success(
            $this->service->createApiToken($data['name'], $data['abilities'] ?? ['*'])
        );
    }

    /**
     * Revoke an API token.
     */
    public function revokeApiToken(int|string $tokenId): JsonResponse
    {
        return ApiResponse::success(
            body: ['revoked' => $this->service->revokeApiToken($tokenId)],
            message: __('user::messages.token_revoked')
        );
    }

    /**
     * Refresh the current access token.
     */
    public function refreshToken(): JsonResponse
    {
        $data = $this->service->refreshToken();

        return ApiResponse::success([
            "user" => new AuthResource($data['user']),
            "token" => $data['token'],
            "expires_at" => $data['expires_at'] ?? null,
        ]);
    }

    /**
     * Generate a short-lived QR login token for the given user.
     */
    public function generateQrToken(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $data = $this->service->generateQrToken((int) $request->integer('user_id'));
        return ApiResponse::success($data);
    }

    /**
     * Report a QR login token's status (pending | consumed | expired) so the
     * generating dialog can auto-close once the user logs in.
     */
    public function qrTokenStatus(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string|uuid']);
        return ApiResponse::success($this->service->qrTokenStatus($request->string('token')));
    }

    /**
     * Exchange a QR login token for a full auth token (one-time use).
     */
    public function qrLogin(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string|uuid']);
        $data = $this->service->qrLogin($request->string('token'));
        return ApiResponse::success([
            'user' => new AuthResource($data['user']),
            'token' => $data['token'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }
}
