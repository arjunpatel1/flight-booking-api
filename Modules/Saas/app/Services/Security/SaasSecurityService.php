<?php

namespace Modules\Saas\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\ActivityLog\Models\AuthenticationLog;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class SaasSecurityService
{
    public function overview(): array
    {
        $users = User::query()->withoutGlobalScopes()->withTrashed();
        $activeUsers = (clone $users)->where('is_active', true)->count();
        $mfaUsers = Schema::hasColumn('users', 'mfa_enabled')
            ? (clone $users)->where('mfa_enabled', true)->count()
            : 0;
        $totalUsers = (clone $users)->count();

        $supportAccess = $this->supportAccess();
        $authentication = $this->authentication();

        return [
            'policy' => [
                'platform_mfa_required' => (bool) config('saas.security.require_platform_mfa', false),
                'enforcement_ready' => ! Schema::hasColumn('users', 'mfa_enabled') || ! (clone $users)->whereNull('tenant_id')->where('is_active', true)->where('mfa_enabled', false)->exists(),
                'suspicious_ip_threshold' => (int) config('saas.security.suspicious_login_ip_threshold', 3),
            ],
            'summary' => [
                'users' => $totalUsers,
                'active_users' => $activeUsers,
                'inactive_users' => max(0, $totalUsers - $activeUsers),
                'platform_accounts' => (clone $users)->whereNull('tenant_id')->count(),
                'tenant_users' => (clone $users)->whereNotNull('tenant_id')->count(),
                'roles' => Role::query()->count(),
                'mfa_enabled' => $mfaUsers,
                'mfa_coverage' => $totalUsers > 0 ? (int) round(($mfaUsers / $totalUsers) * 100) : 0,
                'active_tokens' => $this->activeTokens(),
                'open_support_sessions' => $supportAccess['open_count'],
            ],
            'risks' => $this->risks($totalUsers, $mfaUsers, $activeUsers, $supportAccess, $authentication),
            'support_access' => $supportAccess,
            'authentication' => $authentication,
            'tokens' => $this->tokens(),
            'governance' => $this->governance(),
            'audit' => $this->audit(),
        ];
    }

    private function activeTokens(): int
    {
        if (! Schema::hasTable('personal_access_tokens')) return 0;

        return DB::table('personal_access_tokens')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    private function tokens(): array
    {
        if (! Schema::hasTable('personal_access_tokens')) return [];

        return PersonalAccessToken::query()
            ->with('tokenable:id,name,email,tenant_id')
            ->where('tokenable_type', User::class)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->limit(50)->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'user_id' => $token->tokenable?->id,
                'user' => $token->tokenable?->name,
                'email' => $token->tokenable?->email,
                'tenant_id' => $token->tokenable?->tenant_id,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function governance(): array
    {
        $passkeyUsers = Schema::hasTable('passkeys')
            ? DB::table('passkeys')->select('user_id', DB::raw('COUNT(*) as total'))->groupBy('user_id')->pluck('total', 'user_id')
            : collect();
        $lastLogins = Schema::hasTable('authentication_log')
            ? DB::table('authentication_log')->where('authenticatable_type', User::class)
                ->select('authenticatable_id', DB::raw('MAX(login_at) as last_login_at'))->groupBy('authenticatable_id')->pluck('last_login_at', 'authenticatable_id')
            : collect();

        $accounts = User::query()->withoutGlobalScopes()->withTrashed()->with('roles:id,name,display_name')
            ->whereNull('tenant_id')->orderBy('name')->limit(100)->get()
            ->map(function (User $user) use ($passkeyUsers, $lastLogins) {
                $lastLogin = $lastLogins->get($user->id);
                $lastSeen = $lastLogin ? \Carbon\Carbon::parse($lastLogin) : $user->updated_at;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'active' => (bool) $user->is_active && ! $user->trashed(),
                    'roles' => $user->roles->pluck('name')->values()->all(),
                    'mfa_enabled' => (bool) $user->mfa_enabled,
                    'passkeys' => (int) $passkeyUsers->get($user->id, 0),
                    'last_login_at' => $lastLogin ? \Carbon\Carbon::parse($lastLogin)->toIso8601String() : null,
                    'dormant' => ! $lastSeen || $lastSeen->lt(now()->subDays(30)),
                ];
            })->values();

        $roles = Role::query()->withCount(['users', 'permissions'])->orderByDesc('users_count')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => is_array($role->display_name) ? ($role->display_name[app()->getLocale()] ?? $role->name) : $role->display_name,
                'users' => $role->users_count,
                'permissions' => $role->permissions_count,
                'built_in' => (bool) $role->built_in,
            ])->values();

        return [
            'accounts' => $accounts,
            'roles' => $roles,
            'summary' => [
                'platform_accounts' => $accounts->count(),
                'unprotected' => $accounts->where('mfa_enabled', false)->where('passkeys', 0)->count(),
                'dormant' => $accounts->where('dormant', true)->count(),
                'disabled' => $accounts->where('active', false)->count(),
            ],
        ];
    }

    private function supportAccess(): array
    {
        if (! Schema::hasTable('activity_log')) return ['open_count' => 0, 'recent' => []];

        $minutes = (int) config('saas.support.session_minutes', 30);
        $records = ActivityLog::query()
            ->with('causer:id,name,email')
            ->where('log_name', 'saas_support_mode')
            ->where('event', 'tenant_handoff_issued')
            ->latest()->limit(20)->get();

        $recent = $records->map(function (ActivityLog $record) use ($minutes) {
            $properties = $record->properties ?? collect();
            $expiresIn = (int) data_get($properties, 'expires_in', $minutes * 60);
            $expiresAt = $record->created_at?->copy()->addSeconds($expiresIn);

            return [
                'id' => $record->id,
                'tenant_id' => (int) data_get($properties, 'tenant_id'),
                'actor' => $record->causer?->name,
                'actor_email' => $record->causer?->email,
                'reason' => data_get($properties, 'reason'),
                'ip_address' => data_get($properties, 'ip_address'),
                'created_at' => $record->created_at?->toIso8601String(),
                'expires_at' => $expiresAt?->toIso8601String(),
                'status' => $expiresAt?->isFuture() ? 'active' : 'expired',
            ];
        })->values();

        return ['open_count' => $recent->where('status', 'active')->count(), 'recent' => $recent];
    }

    private function authentication(): array
    {
        if (! Schema::hasTable('authentication_log')) return ['last_24_hours' => 0, 'active_sessions' => 0, 'recent' => []];

        $query = AuthenticationLog::query()->with('authenticatable:id,name,email,tenant_id');
        $window = (int) config('saas.security.suspicious_login_window_minutes', 30);
        $threshold = (int) config('saas.security.suspicious_login_ip_threshold', 3);
        $suspiciousUsers = (clone $query)->where('login_at', '>=', now()->subMinutes($window))
            ->select('authenticatable_id')->groupBy('authenticatable_id')
            ->havingRaw('COUNT(DISTINCT ip_address) >= ?', [$threshold])->pluck('authenticatable_id')->map(fn ($id) => (int) $id);
        $recent = (clone $query)->latest('login_at')->limit(20)->get()->map(fn (AuthenticationLog $log) => [
            'id' => $log->id,
            'user' => $log->authenticatable?->name,
            'email' => $log->authenticatable?->email,
            'tenant_id' => $log->authenticatable?->tenant_id,
            'ip_address' => $log->ip_address,
            'login_at' => $log->login_at?->toIso8601String(),
            'logout_at' => $log->logout_at?->toIso8601String(),
            'status' => $log->logout_at ? 'closed' : 'open',
            'suspicious' => $suspiciousUsers->contains((int) $log->authenticatable_id),
        ])->values();

        return [
            'last_24_hours' => (clone $query)->where('login_at', '>=', now()->subDay())->count(),
            'active_sessions' => (clone $query)->whereNull('logout_at')->where('login_at', '>=', now()->subDay())->count(),
            'suspicious_users' => $suspiciousUsers->count(),
            'recent' => $recent,
        ];
    }

    private function audit(): array
    {
        if (! Schema::hasTable('activity_log')) return ['last_24_hours' => 0, 'security_events' => []];

        $securityNames = ['saas_support_mode', 'saas_customer_success', 'saas_alerts', 'default'];
        $query = ActivityLog::query()->with('causer:id,name,email');
        $events = (clone $query)->whereIn('log_name', $securityNames)->latest()->limit(20)->get()
            ->map(fn (ActivityLog $event) => [
                'id' => $event->id,
                'log_name' => $event->log_name,
                'event' => $event->event,
                'description' => $event->description,
                'actor' => $event->causer?->name,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values();

        return [
            'last_24_hours' => (clone $query)->where('created_at', '>=', now()->subDay())->count(),
            'security_events' => $events,
        ];
    }

    private function risks(int $total, int $mfa, int $active, array $support, array $authentication): array
    {
        return collect([
            $total > $mfa ? ['severity' => 'warning', 'title' => 'MFA coverage needs attention', 'detail' => ($total - $mfa).' accounts do not have MFA enabled.', 'route' => 'admin.users.index'] : null,
            ($total - $active) > 0 ? ['severity' => 'info', 'title' => 'Inactive accounts require review', 'detail' => ($total - $active).' accounts are inactive or disabled.', 'route' => 'admin.users.index'] : null,
            $support['open_count'] > 0 ? ['severity' => 'warning', 'title' => 'Support access currently active', 'detail' => $support['open_count'].' time-limited support sessions are within their access window.', 'route' => 'admin.activity_logs.index'] : null,
            $authentication['active_sessions'] > 0 ? ['severity' => 'info', 'title' => 'Open authentication sessions', 'detail' => $authentication['active_sessions'].' login sessions have no recorded logout.', 'route' => 'admin.authentication_logs.index'] : null,
        ])->filter()->values()->all();
    }
}
