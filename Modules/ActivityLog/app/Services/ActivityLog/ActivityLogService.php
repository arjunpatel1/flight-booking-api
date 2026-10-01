<?php

namespace Modules\ActivityLog\Services\ActivityLog;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\User;

class ActivityLogService implements ActivityLogServiceInterface
{
    /** {@inheritDoc} */
    public function show(int $id): ActivityLog
    {
        return $this->scopeToTenant(ActivityLog::query()->with([
            'subject',
            'causer' => fn (MorphTo $morph) => $morph->morphWith([User::class => ['roles']]),
        ]))->findOrFail($id);
    }

    /**
     * Restrict audit records to the caller's own tenant.
     *
     * activity_log carries no tenant column, and admin_branch — a tenant-scoped
     * role — can read this endpoint, so an unscoped query exposed every other
     * restaurant's activity. Scope by causer: a tenant sees what its own people
     * did. Platform administrators (no tenant_id) still audit the whole estate.
     */
    private function scopeToTenant(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query;
        }

        // Strict models throw when tenant_id was not part of the select, and
        // treating "unknown" as "platform admin" would fail open. Resolve it
        // explicitly instead.
        $attributes = $user->getAttributes();
        $userId = auth()->id();
        $tenantId = array_key_exists('tenant_id', $attributes)
            ? $attributes['tenant_id']
            : ($userId ? DB::table('users')->where('id', $userId)->value('tenant_id') : null);

        if (! $tenantId) {
            return $query;
        }

        // Queried through the DB facade so model hooks and strict-attribute
        // rules cannot interfere with an authorization decision.
        return $query
            ->where('causer_type', User::class)
            ->whereIn(
                'causer_id',
                DB::table('users')->where('tenant_id', $tenantId)->select('id')
            );
    }

    /** {@inheritDoc} */
    public function getStructureFilters(): array
    {
        $tenantId = $this->tenantId();
        $cacheKey = 'activity-log:structure-filters:'.($tenantId ?: 'platform');

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($tenantId): array {
            $activity = DB::table('activity_log');
            if ($tenantId) {
                $activity->where('causer_type', User::class)->whereIn(
                    'causer_id',
                    DB::table('users')->where('tenant_id', $tenantId)->select('id'),
                );
            }

            $eventOptions = (clone $activity)->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')
                ->map(fn ($event) => ['id' => $event, 'name' => __("activitylog::activity_logs.events.$event")])->values();
            $logOptions = (clone $activity)->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')
                ->map(fn ($name) => ['id' => $name, 'name' => $name])->values();
            $userOptions = DB::table('users')->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereIn('id', (clone $activity)->where('causer_type', User::class)->select('causer_id')->distinct())
                ->orderBy('name')->get(['id', 'name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values();

            return [
                ['key' => 'ip', 'label' => __('activitylog::activity_logs.filters.ip'), 'type' => 'text'],
                ['key' => 'batch_uuid', 'label' => __('activitylog::activity_logs.filters.batch_uuid'), 'type' => 'text'],
                ['key' => 'causer_user', 'label' => __('activitylog::activity_logs.filters.causer_user'), 'type' => 'select', 'options' => $userOptions],
                ['key' => 'event', 'label' => __('activitylog::activity_logs.filters.event'), 'type' => 'select', 'multiple' => true, 'options' => $eventOptions],
                ['key' => 'log_name', 'label' => __('activitylog::activity_logs.filters.log_name'), 'type' => 'select', 'multiple' => true, 'options' => $logOptions],
                GlobalStructureFilters::from(),
                GlobalStructureFilters::to(),
            ];
        });
    }

    /** {@inheritDoc} */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $query = $this->scopeToTenant(ActivityLog::query())->with([
            'causer' => fn (MorphTo $morph) => $morph->morphWith([User::class => ['roles']]),
        ]);
        if (filter_var($filters['tracking_only'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->where(function (Builder $tracking) {
                $tracking->where('log_name', 'like', '%order%')
                    ->orWhere('log_name', 'like', '%tracking%')
                    ->orWhere('log_name', 'like', '%payment%')
                    ->orWhere('event', 'like', '%order%')
                    ->orWhere('event', 'like', '%tracking%')
                    ->orWhere('event', 'like', '%payment%');
            });
            unset($filters['tracking_only']);
        }

        return $query
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate());
    }

    public function prune(int $retentionDays): int
    {
        return $this->scopeToTenant(ActivityLog::query())
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->delete();
    }

    private function tenantId(): ?int
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $attributes = $user->getAttributes();
        $tenantId = array_key_exists('tenant_id', $attributes)
            ? $attributes['tenant_id']
            : (auth()->id() ? DB::table('users')->where('id', auth()->id())->value('tenant_id') : null);

        return $tenantId ? (int) $tenantId : null;
    }
}
