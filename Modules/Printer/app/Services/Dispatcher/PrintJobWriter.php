<?php

namespace Modules\Printer\Services\Dispatcher;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\Diagnostics\PrintJobTrace;

readonly class PrintJobWriter
{
    public function __construct(
        private PrintPayloadRenderer $payloadRenderer,
        private PrintDispatchRoutingSupport $routingSupport,
    ) {}

    public function create(
        Printer $printer,
        Order $order,
        PrintContentType $type,
        array $payload,
        bool $retryDuplicate = false,
        ?int $specificId = null,
    ): void {
        $startedAt = microtime(true);
        $config = $printer->mapPrinterConfig();
        $configuredAgentId = data_get($config, 'agent_id');
        $agentId = $this->routingSupport->resolvePrinterAgentId($printer, $configuredAgentId);
        data_set($config, 'agent_id', $agentId);
        data_set($config, 'diagnostics', $this->initialDiagnostics(
            $order,
            $printer,
            $type,
            $agentId,
            $configuredAgentId,
            $specificId,
        ));

        $forceFastEscPosText = $this->payloadRenderer->forcesFastEscPosText($type);
        if ($forceFastEscPosText && $printer->connection_type === PrinterConnectionType::Spooler) {
            data_set($config, 'settings.raw', true);
        }

        $paperSize = PrinterPaperSize::tryFrom(data_get($config, 'settings.media'))
            ?? PrinterPaperSize::Paper80mm;
        $columns = data_get($config, 'settings.columns');
        if (is_numeric($columns)) {
            data_set($payload, '_print.text_columns', (int) $columns);
        }
        $useExperimentalEscPos = $this->payloadRenderer->usesExperimentalEscPos($type);
        $isEscPosPayload = $this->payloadRenderer->isEscPosPayload(
            $printer,
            $config,
            $useExperimentalEscPos,
            $forceFastEscPosText,
        );
        $deduplicationKey = $this->deduplicationKey($order, $printer, $type, $payload, $retryDuplicate);
        $renderLock = Cache::lock(
            "printer:dispatch:{$order->branch_id}:{$order->id}:{$printer->id}:{$type->value}",
            max(10, (int) config('printer.queue.render_lock_seconds', 45)),
        );

        if (! $renderLock->get()) {
            Log::warning('Print dispatch skipped: render already in progress.', $this->logContext($order, $printer, $type));

            return;
        }

        try {
            if ($retryDuplicate && $this->handleExistingRetry(
                $order,
                $printer,
                $type,
                $payload,
                $config,
                $paperSize,
                $isEscPosPayload,
                $useExperimentalEscPos,
                $deduplicationKey,
                $agentId,
            )) {
                return;
            }

            $renderedBytes = $this->payloadRenderer->render(
                $type,
                $payload,
                $paperSize,
                $isEscPosPayload,
                $useExperimentalEscPos,
            );
            $renderedBytes = $this->payloadRenderer->addDeviceSignals(
                $type,
                $config,
                $renderedBytes,
                $isEscPosPayload,
            );
            Log::debug('Print payload rendered for job.', [
                ...$this->logContext($order, $printer, $type),
                'paper_size' => $paperSize->value,
                'connection_type' => data_get($config, 'type'),
                'render_mode' => $useExperimentalEscPos ? 'experimental-escpos' : ($isEscPosPayload ? 'escpos' : 'image'),
                'rendered_base64_bytes' => strlen($renderedBytes),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            $this->persist(
                $order,
                $printer,
                $type,
                $config,
                $renderedBytes,
                $deduplicationKey,
                $retryDuplicate,
                $agentId,
                $configuredAgentId,
                $startedAt,
            );
        } finally {
            $renderLock->release();
        }
    }

    private function handleExistingRetry(
        Order $order,
        Printer $printer,
        PrintContentType $type,
        array $payload,
        array $config,
        PrinterPaperSize $paperSize,
        bool $isEscPosPayload,
        bool $useExperimentalEscPos,
        string $deduplicationKey,
        ?string $agentId,
    ): bool {
        $existingJob = PrintJob::query()
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if (! $existingJob) {
            if (! $this->hasRecentDispatch($deduplicationKey)) {
                return false;
            }

            Log::info('Print job duplicate suppressed: identical payload was dispatched recently.', $this->logContext($order, $printer, $type));

            return true;
        }

        if ($existingJob->status === PrintJobStatus::Pending) {
            $this->rememberRecentDispatch($deduplicationKey);
            $this->publish($existingJob, $order, $printer, $type, $agentId);
            Log::info('Print job retry rebroadcast: job already pending.', [
                'job_id' => $existingJob->id,
                ...$this->logContext($order, $printer, $type),
            ]);

            return true;
        }

        if ($this->shouldSuppressCompletedDuplicate($existingJob, $deduplicationKey)) {
            Log::info('Print job duplicate suppressed: recently completed identical payload.', [
                'job_id' => $existingJob->id,
                ...$this->logContext($order, $printer, $type),
            ]);

            return true;
        }

        $renderedBytes = $this->payloadRenderer->render(
            $type,
            $payload,
            $paperSize,
            $isEscPosPayload,
            $useExperimentalEscPos,
        );
        $renderedBytes = $this->payloadRenderer->addDeviceSignals(
            $type,
            $config,
            $renderedBytes,
            $isEscPosPayload,
        );
        $retryJob = $this->createRetryJob($existingJob, $order, $printer, $type, $config, $renderedBytes);
        $this->rememberRecentDispatch($deduplicationKey);
        $this->publish($retryJob, $order, $printer, $type, $agentId);
        Log::info('Print job retry created with freshly rendered duplicate payload.', [
            'job_id' => $retryJob->id,
            'source_job_id' => $existingJob->id,
            ...$this->logContext($order, $printer, $type),
        ]);

        return true;
    }

    private function persist(
        Order $order,
        Printer $printer,
        PrintContentType $type,
        array $config,
        string $renderedBytes,
        string $deduplicationKey,
        bool $retryDuplicate,
        ?string $agentId,
        ?string $configuredAgentId,
        float $startedAt,
    ): void {
        try {
            $job = PrintJob::query()->create([
                'branch_id' => $order->branch_id,
                'deduplication_key' => $deduplicationKey,
                'printer_config' => $config,
                'rendered_bytes' => $renderedBytes,
                'status' => PrintJobStatus::Pending,
            ]);

            if ($retryDuplicate) {
                $this->rememberRecentDispatch($deduplicationKey);
            }
            $this->publish($job, $order, $printer, $type, $agentId);
            Log::info('Print job created.', [
                'job_id' => $job->id,
                ...$this->logContext($order, $printer, $type),
                'agent_id' => $agentId,
                'agent_source' => filled($configuredAgentId)
                    ? 'printer_config'
                    : (filled($agentId) ? 'active_agent_fallback' : 'unassigned_no_active_agent'),
                'connection_type' => data_get($config, 'type'),
                'duration_ms' => $this->durationMs($startedAt),
                'order_to_print_job_ms' => $order->created_at
                    ? max(0, $order->created_at->diffInMilliseconds(now()))
                    : null,
            ]);
        } catch (QueryException $exception) {
            $this->handleUniqueKeyRace(
                $exception,
                $order,
                $printer,
                $type,
                $config,
                $renderedBytes,
                $deduplicationKey,
                $retryDuplicate,
                $agentId,
            );
        }
    }

    private function handleUniqueKeyRace(
        QueryException $exception,
        Order $order,
        Printer $printer,
        PrintContentType $type,
        array $config,
        string $renderedBytes,
        string $deduplicationKey,
        bool $retryDuplicate,
        ?string $agentId,
    ): void {
        $existingJob = PrintJob::query()
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if (! $existingJob) {
            throw $exception;
        }

        if (! $retryDuplicate) {
            Log::info('Print job skipped: duplicate deduplication key.', $this->logContext($order, $printer, $type));

            return;
        }

        if ($this->shouldSuppressCompletedDuplicate($existingJob, $deduplicationKey)) {
            Log::info('Print job duplicate suppressed after unique-key race: recently completed identical payload.', [
                'job_id' => $existingJob->id,
                ...$this->logContext($order, $printer, $type),
            ]);

            return;
        }

        $retryJob = $this->createRetryJob($existingJob, $order, $printer, $type, $config, $renderedBytes);
        $this->rememberRecentDispatch($deduplicationKey);
        $this->publish($retryJob, $order, $printer, $type, $agentId);
        Log::info('Print job retry created from duplicate deduplication key.', [
            'job_id' => $retryJob->id,
            'source_job_id' => $existingJob->id,
            ...$this->logContext($order, $printer, $type),
        ]);
    }

    private function createRetryJob(
        PrintJob $existingJob,
        Order $order,
        Printer $printer,
        PrintContentType $type,
        array $config,
        ?string $renderedBytes = null,
    ): PrintJob {
        $config = PrintJobTrace::appendToConfig(
            $config,
            'retry_queued',
            'A retry print job was created from an earlier print request.',
            [
                'source_job_id' => $existingJob->id,
                'printer_id' => $printer->id,
                'print_type' => $type->value,
            ],
        );

        return PrintJob::query()->create([
            'branch_id' => $order->branch_id,
            'deduplication_key' => hash('sha256', implode('|', [
                'retry',
                $existingJob->deduplication_key ?: $existingJob->id,
                $order->id,
                $printer->id,
                $type->value,
                microtime(true),
                bin2hex(random_bytes(8)),
            ])),
            'printer_config' => $config,
            'rendered_bytes' => $renderedBytes ?: $existingJob->rendered_bytes,
            'status' => PrintJobStatus::Pending,
        ]);
    }

    private function publish(
        PrintJob $job,
        Order $order,
        Printer $printer,
        PrintContentType $type,
        ?string $agentId,
    ): void {
        $this->routingSupport->clearEmptyPollCache($order->branch_id, $agentId);
        $this->routingSupport->broadcastPrintJobCreated($job, $printer, $type);
    }

    private function initialDiagnostics(
        Order $order,
        Printer $printer,
        PrintContentType $type,
        ?string $agentId,
        ?string $configuredAgentId,
        ?int $specificId,
    ): array {
        return PrintJobTrace::build(
            $order,
            $type,
            'queued',
            'Print job created and waiting for agent pickup.',
            [
                'printer_id' => $printer->id,
                'printer_name' => $printer->name,
                'agent_id' => $agentId,
                'specific_id' => $specificId,
                'route_source' => filled($configuredAgentId)
                    ? 'printer_config'
                    : (filled($agentId) ? 'active_agent_fallback' : 'unassigned'),
            ],
        );
    }

    private function deduplicationKey(
        Order $order,
        Printer $printer,
        PrintContentType $type,
        array $payload,
        bool $retryDuplicate,
    ): string {
        $requestKey = trim((string) request()?->header('Idempotency-Key'));
        $payloadScope = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $dispatchScope = ($type === PrintContentType::Kitchen || $retryDuplicate)
            ? $payloadScope
            : ($requestKey !== '' ? $requestKey : $payloadScope);

        return hash('sha256', implode('|', [
            $order->branch_id,
            $order->id,
            $printer->id,
            $type->value,
            $dispatchScope,
        ]));
    }

    private function shouldSuppressCompletedDuplicate(PrintJob $job, string $deduplicationKey): bool
    {
        if ($job->status !== PrintJobStatus::Success) {
            return false;
        }

        $graceSeconds = $this->duplicateGraceSeconds();
        $completedAt = $job->completed_at ?: $job->updated_at;

        return ($graceSeconds > 0 && $completedAt?->greaterThanOrEqualTo(now()->subSeconds($graceSeconds)))
            || $this->hasRecentDispatch($deduplicationKey);
    }

    private function hasRecentDispatch(string $deduplicationKey): bool
    {
        return Cache::has($this->recentDispatchCacheKey($deduplicationKey));
    }

    private function rememberRecentDispatch(string $deduplicationKey): void
    {
        $graceSeconds = $this->duplicateGraceSeconds();
        if ($graceSeconds > 0) {
            Cache::put($this->recentDispatchCacheKey($deduplicationKey), true, $graceSeconds);
        }
    }

    private function duplicateGraceSeconds(): int
    {
        return max(0, (int) config('printer.queue.completed_duplicate_grace_seconds', 12));
    }

    private function recentDispatchCacheKey(string $deduplicationKey): string
    {
        return "printer:recent-dispatch:{$deduplicationKey}";
    }

    private function logContext(Order $order, Printer $printer, PrintContentType $type): array
    {
        return [
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'printer_id' => $printer->id,
            'type' => $type->value,
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
