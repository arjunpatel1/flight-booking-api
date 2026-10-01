<?php

namespace Modules\ActivityLog\Services\AuthenticationLog;

use App\NexDine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ActivityLog\Models\AuthenticationLog;

class AuthenticationLogService implements AuthenticationLogServiceInterface
{
    /** @inheritDoc */
    public function get(array $filters, ?array $sorts = []): LengthAwarePaginator
    {
        return $this->scopedQuery()
            ->filters($filters)
            ->sortBy($sorts)
            ->with(["authenticatable" => fn ($relation) => $relation->withoutGlobalScopes()->withTrashed()->with("roles")])
            ->paginate(NexDine::paginate());
    }

    /** @inheritDoc */
    public function show(int $id): AuthenticationLog
    {
        return $this->scopedQuery()->with(["authenticatable" => fn ($relation) => $relation->withoutGlobalScopes()->withTrashed()->with("roles")])->findOrFail($id);
    }
    private function scopedQuery(): Builder
    {
        $query = AuthenticationLog::query();
        $user = auth()->user();
        if (! $user) return $query->whereRaw('1 = 0');
        $tenantId = DB::table('users')->where('id', $user->getKey())->value('tenant_id');
        if ($tenantId) {
            $query->where('authenticatable_type', (new User)->getMorphClass())
                ->whereIn('authenticatable_id', DB::table('users')->where('tenant_id', $tenantId)->select('id'));
        }
        return $query;
    }
}
