<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Http\Controllers\Controller;
use Modules\ActivityLog\Models\AuthenticationLog;
use Modules\Saas\Services\Security\SaasSecurityService;
use Modules\Support\ApiResponse;
use Modules\User\Models\User;

class SaasSecurityController extends Controller
{
    public function index(SaasSecurityService $service): JsonResponse
    {
        return ApiResponse::success($service->overview());
    }

    public function revokeToken(Request $request, PersonalAccessToken $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        abort_if((int) $request->user()?->currentAccessToken()?->id === (int) $token->id, 422, 'Your current session cannot be revoked from this action.');

        $owner = $token->tokenable;
        abort_unless($owner instanceof User, 404);
        $metadata = ['token_id' => $token->id, 'token_name' => $token->name, 'user_id' => $owner->id, 'reason' => $data['reason']];

        DB::transaction(function () use ($request, $token, $owner, $metadata) {
            activity('saas_security')->event('api_token_revoked')->causedBy($request->user())->performedOn($owner)
                ->withProperties($metadata)->log('API access token revoked.');
            $token->delete();
        });

        return ApiResponse::success(['revoked' => true], 'API token revoked.');
    }

    public function revokeUserSessions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $currentTokenId = (int) $request->user()?->currentAccessToken()?->id;

        $revoked = DB::transaction(function () use ($request, $user, $data, $currentTokenId) {
            $tokens = $user->tokens()->when($request->user()?->is($user), fn ($query) => $query->where('id', '!=', $currentTokenId));
            $count = $tokens->count();
            $tokens->delete();

            if (Schema::hasTable('authentication_log')) {
                AuthenticationLog::query()->where('authenticatable_type', User::class)->where('authenticatable_id', $user->id)
                    ->whereNull('logout_at')->update(['logout_at' => now()]);
            }

            activity('saas_security')->event('user_sessions_revoked')->causedBy($request->user())->performedOn($user)
                ->withProperties(['user_id' => $user->id, 'tokens_revoked' => $count, 'reason' => $data['reason']])
                ->log('User sessions revoked by SaaS administrator.');

            return $count;
        });

        return ApiResponse::success(['revoked_tokens' => $revoked], 'User sessions revoked.');
    }
}
