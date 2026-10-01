<?php

namespace Modules\Voice\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Modules\Voice\Models\VoiceTemplate;

class VoiceTemplateCacheService
{
    private const CACHE_PREFIX = 'voice_templates:';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get templates for a branch from cache or database
     */
    public function getTemplates(int $branchId): array
    {
        $cacheKey = $this->getCacheKey($branchId);
        $this->ensureDefaultTemplates($branchId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($branchId) {
            return VoiceTemplate::where('branch_id', $branchId)
                ->orderBy('priority', 'asc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        });
    }

    /**
     * Save template and update cache
     */
    public function saveTemplate(int $branchId, array $data): VoiceTemplate
    {
        $payload = array_merge($data, ['branch_id' => $branchId]);
        unset($payload['id']);

        if (!empty($data['id'])) {
            $template = VoiceTemplate::whereKey($data['id'])->first();

            if (!$template) {
                throw (new ModelNotFoundException())->setModel(VoiceTemplate::class, [$data['id']]);
            }

            if ((int) $template->branch_id !== $branchId) {
                throw new AuthorizationException('You are not authorized to update this voice template.');
            }

            $template->update($payload);
        } else {
            $template = VoiceTemplate::create($payload);
        }

        // Invalidate cache
        $this->invalidateCache($branchId);

        return $template;
    }

    /**
     * Delete template and update cache
     */
    public function deleteTemplate(int $templateId, int $branchId): bool
    {
        $deleted = VoiceTemplate::where('id', $templateId)
            ->where('branch_id', $branchId)
            ->delete();

        if ($deleted) {
            $this->invalidateCache($branchId);
        }

        return $deleted;
    }

    /**
     * Invalidate cache for a branch
     */
    public function invalidateCache(int $branchId): void
    {
        $cacheKey = $this->getCacheKey($branchId);
        Cache::forget($cacheKey);
    }

    /**
     * Get cache key for branch
     */
    private function getCacheKey(int $branchId): string
    {
        return self::CACHE_PREFIX . $branchId;
    }

    /**
     * Bulk save templates
     */
    public function bulkSaveTemplates(int $branchId, array $templates): array
    {
        $savedTemplates = [];

        foreach ($templates as $templateData) {
            $savedTemplates[] = $this->saveTemplate($branchId, $templateData);
        }

        return $savedTemplates;
    }

    /**
     * Bulk delete templates
     */
    public function bulkDeleteTemplates(int $branchId, array $templateIds): int
    {
        $deleted = VoiceTemplate::where('branch_id', $branchId)
            ->whereIn('id', $templateIds)
            ->delete();

        if ($deleted > 0) {
            $this->invalidateCache($branchId);
        }

        return $deleted;
    }

    private function ensureDefaultTemplates(int $branchId): void
    {
        foreach (VoiceTemplate::getDefaultTemplates($branchId) as $template) {
            VoiceTemplate::firstOrCreate(
                [
                    'branch_id' => $branchId,
                    'event_type' => $template['event_type'],
                    'template_name' => $template['template_name'],
                ],
                $template
            );
        }
    }
}
