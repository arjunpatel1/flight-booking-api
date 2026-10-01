<?php

namespace Modules\Saas\Services\Workspace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;

class WaiterActivationChallengeService
{
    public function __construct(private readonly ActivationKeyService $keys) {}

    public function issue(Tenant $tenant, ?int $createdBy = null): array
    {
        $branchId = Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->orderByDesc('is_main')->value('id');
        $token = Str::random(64);
        $code = $this->keys->randomKey();
        $expiresAt = now()->addMinutes((int) config('saas.self_service.activation_ttl_minutes', 15));

        DB::transaction(function () use ($tenant, $branchId, $token, $code, $createdBy, $expiresAt): void {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
            DB::table('tenant_app_activation_challenges')->where('tenant_id', $tenant->id)
                ->where('app', 'waiter_app')->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_app_activation_challenges')->insert([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'app' => 'waiter_app',
                'token_hash' => hash('sha256', $token),
                'code_hash' => hash('sha256', $this->keys->normalise($code)),
                'created_by' => $createdBy,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        return [
            'type' => 'nexdine_waiter_activation',
            'version' => 3,
            'tenant' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'activation_token' => $token,
            'activation_code' => $code,
            'redeem_url' => rtrim((string) config('saas.self_service.public_api_base_url'), '/').'/saas/client-config/activation/redeem',
            'issued_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function consume(string $credential, string $kind, Request $request): ?Tenant
    {
        $normalised = $kind === 'code' ? $this->keys->normalise($credential) : $credential;
        $column = $kind === 'code' ? 'code_hash' : 'token_hash';

        return DB::transaction(function () use ($column, $normalised, $request): ?Tenant {
            $challenge = DB::table('tenant_app_activation_challenges')
                ->where($column, hash('sha256', $normalised))->lockForUpdate()->first();

            if (! $challenge || $challenge->consumed_at || $challenge->revoked_at || now()->gte($challenge->expires_at)) {
                return null;
            }

            DB::table('tenant_app_activation_challenges')->where('id', $challenge->id)->update([
                'consumed_at' => now(),
                'consumed_ip' => $request->ip(),
                'consumed_user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                'updated_at' => now(),
            ]);

            return Tenant::query()->withoutGlobalScopes()->whereKey($challenge->tenant_id)->where('is_active', true)->first();
        }, 3);
    }
}
