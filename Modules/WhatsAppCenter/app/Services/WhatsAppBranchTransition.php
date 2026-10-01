<?php

namespace Modules\WhatsAppCenter\Services;

use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\WhatsAppCenter\Models\WhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;

class WhatsAppBranchTransition
{
    /**
     * Retire runtime state that belongs to branches removed from an assignment.
     * Call this inside the same transaction that updates the assignment.
     *
     * @return array{removed_branch_ids: array<int>, catalog_mapping_ids: array<int>, closed_conversations: int, expired_sessions: int}
     */
    public function retireRemovedBranches(WhatsAppTenantAssignment $assignment, array $newBranchIds): array
    {
        $knownBranchIds = Branch::query()->withoutGlobalActive()
            ->where('tenant_id', $assignment->tenant_id)
            ->where('is_active', true)
            ->pluck('id')
            ->merge(WhatsAppCatalogProduct::query()->withoutGlobalTenant()
                ->where('tenant_id', $assignment->tenant_id)
                ->where('provider_profile_id', $assignment->provider_profile_id)
                ->pluck('branch_id'))
            ->merge(DB::table('whatsapp_conversations')
                ->where('assignment_id', $assignment->id)
                ->whereNull('closed_at')
                ->whereNotNull('branch_id')
                ->pluck('branch_id'))
            ->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $removedBranchIds = collect($this->removedBranchIds(
            $assignment->allowed_branch_ids ?? [],
            $newBranchIds,
            $knownBranchIds->all(),
        ));

        if ($removedBranchIds->isEmpty()) {
            return $this->emptyResult();
        }

        $conversationIds = DB::table('whatsapp_conversations')
            ->where('assignment_id', $assignment->id)
            ->whereIn('branch_id', $removedBranchIds)
            ->whereNull('closed_at')
            ->pluck('id');
        $expiredSessions = $conversationIds->isEmpty() ? 0 : DB::table('whatsapp_order_sessions')
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('order_id')
            ->whereNotIn('state', ['completed', 'cancelled', 'expired'])
            ->update(['state' => 'expired', 'expires_at' => now(), 'updated_at' => now()]);
        $closedConversations = $conversationIds->isEmpty() ? 0 : DB::table('whatsapp_conversations')
            ->whereIn('id', $conversationIds)
            ->update(['state' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);

        $mappingIds = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
            ->where('tenant_id', $assignment->tenant_id)
            ->where('provider_profile_id', $assignment->provider_profile_id)
            ->whereIn('branch_id', $removedBranchIds)
            ->where(function ($query) {
                $query->where('status', '!=', 'disabled')->orWhere('sync_status', '!=', 'disabled');
            })
            ->pluck('id');
        if ($mappingIds->isNotEmpty()) {
            WhatsAppCatalogProduct::query()->withoutGlobalTenant()->whereIn('id', $mappingIds)->update([
                'status' => 'disabled',
                'sync_status' => 'pending',
                'last_sync_error' => null,
                'updated_at' => now(),
            ]);
        }

        return [
            'removed_branch_ids' => $removedBranchIds->all(),
            'catalog_mapping_ids' => $mappingIds->map(fn ($id) => (int) $id)->all(),
            'closed_conversations' => $closedConversations,
            'expired_sessions' => $expiredSessions,
        ];
    }

    /** @return array<int> */
    public function removedBranchIds(array $oldBranchIds, array $newBranchIds, array $activeBranchIds): array
    {
        $normalize = fn (array $ids) => collect($ids)->map(fn ($id) => (int) $id)->filter()->unique();
        $active = $normalize($activeBranchIds);
        $old = $normalize($oldBranchIds);
        $new = $normalize($newBranchIds);

        return ($old->isEmpty() ? $active : $old)
            ->diff($new->isEmpty() ? $active : $new)
            ->values()->all();
    }

    /** @return array{removed_branch_ids: array<int>, catalog_mapping_ids: array<int>, closed_conversations: int, expired_sessions: int} */
    private function emptyResult(): array
    {
        return ['removed_branch_ids' => [], 'catalog_mapping_ids' => [], 'closed_conversations' => 0, 'expired_sessions' => 0];
    }
}
