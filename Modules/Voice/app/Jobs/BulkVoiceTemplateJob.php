<?php

namespace Modules\Voice\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Modules\Voice\Services\VoiceAnnouncementService;
use Throwable;

class BulkVoiceTemplateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 300; // 5 minutes

    public function __construct(
        private int $branchId,
        private array $templates,
        private string $operation // 'save' or 'delete'
    ) {
        $this->onQueue('voice');
    }

    public function handle(VoiceAnnouncementService $voiceService): void
    {
        try {
            if ($this->operation === 'save') {
                $voiceService->bulkSaveTemplates($this->branchId, $this->templates);
                Log::info("Bulk save templates completed for branch {$this->branchId}");
            } elseif ($this->operation === 'delete') {
                $voiceService->bulkDeleteTemplates($this->branchId, $this->templates);
                Log::info("Bulk delete templates completed for branch {$this->branchId}");
            }
        } catch (\Exception $e) {
            Log::error("Bulk template operation failed for branch {$this->branchId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Bulk template job failed for branch {$this->branchId}: " . $exception->getMessage());
    }

    /**
     * Dispatch bulk save templates as batched jobs
     */
    public static function dispatchBulkSave(int $branchId, array $templates): Batch
    {
        $jobs = [];
        $chunkSize = 10; // Process 10 templates per job

        foreach (array_chunk($templates, $chunkSize) as $chunk) {
            $jobs[] = new self($branchId, $chunk, 'save');
        }

        return Bus::batch($jobs)
            ->then(function (Batch $batch) {
                Log::info("Bulk save templates batch completed: {$batch->id}");
            })
            ->catch(function (Batch $batch, Throwable $e) {
                Log::error("Bulk save templates batch failed: {$batch->id} - " . $e->getMessage());
            })
            ->onQueue('voice')
            ->dispatch();
    }

    /**
     * Dispatch bulk delete templates as batched jobs
     */
    public static function dispatchBulkDelete(int $branchId, array $templateIds): Batch
    {
        $jobs = [];
        $chunkSize = 50; // Delete 50 templates per job

        foreach (array_chunk($templateIds, $chunkSize) as $chunk) {
            $jobs[] = new self($branchId, $chunk, 'delete');
        }

        return Bus::batch($jobs)
            ->then(function (Batch $batch) {
                Log::info("Bulk delete templates batch completed: {$batch->id}");
            })
            ->catch(function (Batch $batch, Throwable $e) {
                Log::error("Bulk delete templates batch failed: {$batch->id} - " . $e->getMessage());
            })
            ->onQueue('voice')
            ->dispatch();
    }
}
