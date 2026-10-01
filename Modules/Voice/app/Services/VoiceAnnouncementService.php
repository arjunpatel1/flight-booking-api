<?php

namespace Modules\Voice\Services;

use Modules\Voice\Models\VoiceSetting;
use Modules\Voice\Models\VoiceTemplate;
use Modules\Voice\Models\VoiceHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class VoiceAnnouncementService
{
    /**
     * Trigger a voice announcement for an order.
     */
    public function triggerAnnouncement(int $branchId, ?int $orderId, string $eventType, array $variables = []): bool
    {
        $lock = Cache::lock($this->announcementLockKey($branchId, $orderId, $eventType, $variables), 30);

        if (!$lock->get()) {
            Log::warning('Voice announcement skipped because another worker is processing the same announcement.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'event_type' => $eventType,
            ]);

            return true;
        }

        try {
            // Check if voice is enabled for the branch
            $settings = VoiceSetting::where('branch_id', $branchId)->first();
            
            if (!$settings || !$settings->voice_enabled) {
                Log::info("Voice announcements disabled for branch {$branchId}");
                return false;
            }

            $template = $this->resolveTemplate($branchId, $eventType);

            if (!$template) {
                Log::warning("No active template found for event type: {$eventType}");
                return false;
            }

            if ($orderId && $this->hasExistingOrderAnnouncement($branchId, $orderId, $eventType)) {
                Log::info('Voice announcement skipped because it was already recorded.', [
                    'branch_id' => $branchId,
                    'order_id' => $orderId,
                    'event_type' => $eventType,
                ]);

                return true;
            }

            // Substitute variables in the template
            $announcementText = $template->substituteVariables($variables);

            $this->recordAndBroadcast($branchId, $orderId, $announcementText, $eventType, $settings);

            $orderInfo = $orderId ? "order {$orderId}" : "manual trigger";
            Log::info("Voice announcement triggered for {$orderInfo}, event: {$eventType}");
            return true;

        } catch (\Exception $e) {
            report($e);
            Log::error('Failed to trigger voice announcement.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Trigger a direct announcement that does not require a saved template.
     */
    public function triggerCustomAnnouncement(int $branchId, ?int $orderId, string $announcementText, string $eventType = 'TestVoice'): bool
    {
        $lock = Cache::lock($this->announcementLockKey($branchId, $orderId, $eventType, ['text' => $announcementText]), 30);

        if (!$lock->get()) {
            Log::warning('Custom voice announcement skipped because another worker is processing the same announcement.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'event_type' => $eventType,
            ]);

            return true;
        }

        try {
            $settings = VoiceSetting::where('branch_id', $branchId)->first();

            if (!$settings || !$settings->voice_enabled) {
                Log::info("Voice announcements disabled for branch {$branchId}");
                return false;
            }

            if ($orderId && $this->hasExistingOrderAnnouncement($branchId, $orderId, $eventType)) {
                Log::info('Custom voice announcement skipped because it was already recorded.', [
                    'branch_id' => $branchId,
                    'order_id' => $orderId,
                    'event_type' => $eventType,
                ]);

                return true;
            }

            $this->recordAndBroadcast($branchId, $orderId, $announcementText, $eventType, $settings);

            Log::info("Custom voice announcement triggered for branch {$branchId}, event: {$eventType}");
            return true;
        } catch (\Exception $e) {
            report($e);
            Log::error('Failed to trigger custom voice announcement.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Trigger a templated announcement but record it under a distinct event key.
     *
     * This keeps one configurable template (for example OrderDelayed) while still
     * allowing operational checkpoints such as OrderDelayed15/20/30 to be
     * de-duplicated independently.
     */
    public function triggerTemplateAnnouncement(
        int $branchId,
        ?int $orderId,
        string $templateEventType,
        string $historyEventType,
        array $variables = []
    ): bool {
        $lock = Cache::lock(
            $this->announcementLockKey($branchId, $orderId, $historyEventType, $variables),
            30
        );

        if (!$lock->get()) {
            Log::warning('Templated voice announcement skipped because another worker is processing it.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'template_event_type' => $templateEventType,
                'history_event_type' => $historyEventType,
            ]);

            return true;
        }

        try {
            $settings = VoiceSetting::where('branch_id', $branchId)->first();

            if (!$settings || !$settings->voice_enabled) {
                Log::info("Voice announcements disabled for branch {$branchId}");
                return false;
            }

            $template = $this->resolveTemplate($branchId, $templateEventType);

            if (!$template) {
                Log::warning("No active template found for event type: {$templateEventType}");
                return false;
            }

            if ($orderId && $this->hasExistingOrderAnnouncement($branchId, $orderId, $historyEventType)) {
                Log::info('Templated voice announcement skipped because it was already recorded.', [
                    'branch_id' => $branchId,
                    'order_id' => $orderId,
                    'history_event_type' => $historyEventType,
                ]);

                return true;
            }

            $announcementText = $template->substituteVariables($variables);
            $this->recordAndBroadcast($branchId, $orderId, $announcementText, $historyEventType, $settings);

            return true;
        } catch (\Exception $e) {
            report($e);
            Log::error('Failed to trigger templated voice announcement.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'template_event_type' => $templateEventType,
                'history_event_type' => $historyEventType,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Pick the template that should speak for an event type.
     *
     * Priority is the selector; is_default only breaks a priority tie. The
     * trailing id keeps the choice stable when both tie, otherwise the database
     * is free to return a different row each call and the branch hears a
     * different announcement for the same event.
     */
    private function resolveTemplate(int $branchId, string $eventType): ?VoiceTemplate
    {
        // A newly provisioned branch may receive its first operational event
        // before an administrator ever opens Voice settings. Ensure the
        // shipped templates exist on that first event as well.
        app(VoiceTemplateCacheService::class)->getTemplates($branchId);

        return VoiceTemplate::where('branch_id', $branchId)
            ->where('event_type', $eventType)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function recordAndBroadcast(int $branchId, ?int $orderId, string $announcementText, string $eventType, VoiceSetting $settings): void
    {
        $recorded = DB::transaction(function () use ($branchId, $orderId, $announcementText, $eventType, $settings): ?VoiceHistory {
            if ($orderId && $this->hasExistingOrderAnnouncement($branchId, $orderId, $eventType)) {
                return null;
            }

            return $this->logVoiceHistory($branchId, $orderId, $announcementText, $eventType, $settings);
        });

        if (!$recorded) {
            return;
        }

        try {
            event(new \Modules\Voice\Events\VoiceAnnouncementTriggered(
                $recorded->id,
                $branchId,
                $orderId,
                $announcementText,
                $eventType,
                $settings,
                ...$this->resolveActor(),
            ));
        } catch (\Exception $e) {
            report($e);
            Log::warning('Voice announcement event broadcast failed.', [
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Who caused this announcement.
     *
     * The voice listeners are registered synchronously, so they still run
     * inside the originating request and the authenticated user plus the
     * device header are available here. Announcements raised from queued jobs
     * (delay checks) have no request, so both come back null and no client
     * suppresses them — which is correct for system-generated alerts.
     *
     * @return array{actorUserId: int|null, actorDeviceId: string|null}
     */
    private function resolveActor(): array
    {
        $request = request();

        return [
            'actorUserId' => auth()->id(),
            'actorDeviceId' => $request?->header('X-NexDine-Device-Id'),
        ];
    }

    private function hasExistingOrderAnnouncement(int $branchId, int $orderId, string $eventType): bool
    {
        return VoiceHistory::where('branch_id', $branchId)
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->exists();
    }

    private function announcementLockKey(int $branchId, ?int $orderId, string $eventType, array $variables): string
    {
        $scope = $orderId ? "order:{$orderId}" : 'manual:' . hash('sha256', json_encode($variables));

        return "voice:announcement:{$branchId}:{$eventType}:{$scope}";
    }

    /**
     * Log voice announcement history.
     */
    protected function logVoiceHistory(int $branchId, ?int $orderId, string $announcementText, string $eventType, VoiceSetting $settings): VoiceHistory
    {
        return VoiceHistory::create([
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'announcement_text' => $announcementText,
            'event_type' => $eventType,
            'voice_gender' => $settings->voice_gender,
            'device_id' => $settings->selected_device_id,
            'device_name' => $settings->selected_device_name,
            'volume' => $settings->voice_volume,
            'success' => true,
        ]);
    }

    /**
     * Get voice settings for a branch with caching.
     */
    public function getSettings(int $branchId): ?VoiceSetting
    {
        $cacheService = app(VoiceSettingsCacheService::class);
        return $cacheService->getSettings($branchId);
    }

    /**
     * Save voice settings for a branch with cache invalidation.
     */
    public function saveSettings(int $branchId, array $settingsData): VoiceSetting
    {
        $cacheService = app(VoiceSettingsCacheService::class);
        return $cacheService->saveSettings($branchId, $settingsData);
    }

    /**
     * Get voice templates for a branch with caching.
     */
    public function getTemplates(int $branchId): array
    {
        $cacheService = app(VoiceTemplateCacheService::class);
        return $cacheService->getTemplates($branchId);
    }

    /**
     * Save voice template for a branch with cache invalidation.
     */
    public function saveTemplate(int $branchId, array $templateData): VoiceTemplate
    {
        $cacheService = app(VoiceTemplateCacheService::class);
        return $cacheService->saveTemplate($branchId, $templateData);
    }

    /**
     * Delete voice template with cache invalidation.
     */
    public function deleteTemplate(int $templateId, int $branchId): bool
    {
        $cacheService = app(VoiceTemplateCacheService::class);
        return $cacheService->deleteTemplate($templateId, $branchId);
    }

    /**
     * Get voice history for a branch with eager loading.
     */
    public function getHistory(int $branchId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return VoiceHistory::where('branch_id', $branchId)
            ->with(['order:id,order_number,status'])
            ->when($filters['event_type'] ?? null, fn($query, string $eventType) => $query->where('event_type', $eventType))
            ->when(array_key_exists('success', $filters) && !is_null($filters['success']), fn($query) => $query->where('success', (bool) $filters['success']))
            ->when($filters['from'] ?? null, fn($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Bulk save templates for a branch.
     */
    public function bulkSaveTemplates(int $branchId, array $templates): array
    {
        $cacheService = app(VoiceTemplateCacheService::class);
        return $cacheService->bulkSaveTemplates($branchId, $templates);
    }

    /**
     * Bulk delete templates for a branch.
     */
    public function bulkDeleteTemplates(int $branchId, array $templateIds): int
    {
        $cacheService = app(VoiceTemplateCacheService::class);
        return $cacheService->bulkDeleteTemplates($branchId, $templateIds);
    }
}
