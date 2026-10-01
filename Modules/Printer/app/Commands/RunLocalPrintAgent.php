<?php

namespace Modules\Printer\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RunLocalPrintAgent extends Command
{
    protected $signature = 'printer:agent-work
        {--agent_id= : Agent identifier configured for this workstation}
        {--branch_id= : Branch ID served by this workstation}
        {--server_url= : Production API base URL for HTTP polling, for example https://api.example.com/api/v1}
        {--agent_secret= : Agent HMAC secret for HTTP polling}
        {--printer_queue= : Local CUPS queue override, for example POS-80}
        {--once : Poll once and exit}
        {--sleep=0.2 : Initial seconds between polls when no jobs are available}
        {--max_sleep=5.0 : Maximum seconds between idle polls}
        {--long_poll=20.0 : Seconds the server should wait for a new job before returning an empty poll}';

    protected $description = 'Run the local silent-print worker for spooler and TCP receipt printers.';

    public function handle(AgentPollServiceInterface $pollService): int
    {
        if ($this->usesHttpPolling()) {
            return $this->handleHttpPolling();
        }

        $agent = $this->resolveAgent();
        $sleepSeconds = $this->sleepSeconds();
        $maxSleepSeconds = $this->maxSleepSeconds();
        $currentSleepSeconds = $sleepSeconds;

        $this->info("Agent started: {$agent->agent_id}, branch {$agent->branch_id}, mode=db");

        do {
            $hadJobs = false;

            try {
                $jobs = $pollService->poll($agent, (int) $agent->branch_id);
                $hadJobs = $jobs->isNotEmpty();
                $currentSleepSeconds = $hadJobs
                    ? $sleepSeconds
                    : min($currentSleepSeconds * 1.5, $maxSleepSeconds);

                foreach ($jobs as $job) {
                    try {
                        $this->info("Job found: {$job['job_id']}");
                        $this->deliver($job);
                        $pollService->report($agent, $job['job_id'], PrintJobStatus::Success);
                        $this->info("Job marked completed: {$job['job_id']}");
                    } catch (Throwable $exception) {
                        $pollService->report($agent, $job['job_id'], PrintJobStatus::Failed, $exception->getMessage());
                        $this->error("Failed job {$job['job_id']}: {$exception->getMessage()}");
                    }
                }
            } catch (Throwable $exception) {
                $this->error("Print poll failed: {$exception->getMessage()}");
                $currentSleepSeconds = min($currentSleepSeconds * 1.5, $maxSleepSeconds);
            }

            if (! $this->option('once') && ! $hadJobs) {
                usleep((int) round($currentSleepSeconds * 1_000_000));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function usesHttpPolling(): bool
    {
        return filled($this->option('server_url'))
            || filled($this->option('agent_secret'))
            || filled(env('PRINT_AGENT_SERVER_URL'))
            || filled(env('PRINT_AGENT_SECRET'));
    }

    private function sleepSeconds(): float
    {
        return max(0.0, (float) $this->option('sleep'));
    }

    private function maxSleepSeconds(): float
    {
        return max($this->sleepSeconds(), (float) $this->option('max_sleep'));
    }

    private function handleHttpPolling(): int
    {
        $agentId = trim((string) $this->option('agent_id'));
        $branchId = (int) $this->option('branch_id');
        $serverUrl = rtrim(trim((string) ($this->option('server_url') ?: env('PRINT_AGENT_SERVER_URL'))), '/');
        $secret = trim((string) ($this->option('agent_secret') ?: env('PRINT_AGENT_SECRET')));
        $sleepSeconds = $this->sleepSeconds();
        $maxSleepSeconds = $this->maxSleepSeconds();
        $longPollSeconds = $this->longPollSeconds();
        $currentSleepSeconds = $sleepSeconds;

        if ($agentId === '' || $branchId <= 0 || $serverUrl === '' || $secret === '') {
            $this->error('HTTP print agent requires --agent_id, --branch_id, --server_url and --agent_secret.');
            return self::FAILURE;
        }

        $this->info("Agent started: {$agentId}, branch {$branchId}, mode=http");
        $this->info("Polling server URL: {$serverUrl}/agents/{$agentId}/poll");
        $this->info("Sleep when idle: {$sleepSeconds}s");
        $this->info("Max idle sleep: {$maxSleepSeconds}s");
        $this->info("Long poll wait: {$longPollSeconds}s");
        if (filled($this->option('printer_queue'))) {
            $this->info("Local queue override: {$this->option('printer_queue')}");
        }

        do {
            $hadJobs = false;

            try {
                $pollResponse = $this->pollRemoteJobs($serverUrl, $agentId, $secret, $branchId, $longPollSeconds);
                $jobs = (array) ($pollResponse['jobs'] ?? []);
                $hadJobs = ! empty($jobs);
                $retryAfter = (float) ($pollResponse['retry_after'] ?? 0);
                if ($hadJobs) {
                    $currentSleepSeconds = $sleepSeconds;
                } elseif ($longPollSeconds > 0) {
                    $currentSleepSeconds = min(max($retryAfter, $sleepSeconds), $maxSleepSeconds);
                } else {
                    $currentSleepSeconds = min(max($retryAfter, $currentSleepSeconds * 1.5), $maxSleepSeconds);
                }

                foreach ($jobs as $job) {
                    $jobId = (string) ($job['job_id'] ?? '');
                    $this->info("Job found: {$jobId}");

                    try {
                        $this->deliver($job);
                        $this->reportRemoteJob($serverUrl, $agentId, $secret, $jobId, PrintJobStatus::Success);
                        $this->info("Job marked completed: {$jobId}");
                    } catch (Throwable $exception) {
                        $this->reportRemoteJob($serverUrl, $agentId, $secret, $jobId, PrintJobStatus::Failed, $exception->getMessage());
                        $this->error("Failed job {$jobId}: {$exception->getMessage()}");
                    }
                }
            } catch (Throwable $exception) {
                $this->error("Print poll failed: {$exception->getMessage()}");
                $currentSleepSeconds = min($currentSleepSeconds * 1.5, $maxSleepSeconds);
            }

            if (! $this->option('once') && ! $hadJobs) {
                usleep((int) round($currentSleepSeconds * 1_000_000));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * @throws ConnectionException
     */
    private function pollRemoteJobs(string $serverUrl, string $agentId, string $secret, int $branchId, float $longPollSeconds): array
    {
        $payload = ['branch_id' => $branchId];

        if ($longPollSeconds > 0) {
            $payload['wait_seconds'] = $longPollSeconds;
        }

        $response = Http::timeout(max(20, (int) ceil($longPollSeconds + 10)))
            ->acceptJson()
            ->withHeaders($this->signedHeaders($agentId, $secret, $payload))
            ->post("{$serverUrl}/agents/{$agentId}/poll", $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Poll HTTP {$response->status()}: {$response->body()}");
        }

        return (array) $response->json();
    }

    private function longPollSeconds(): float
    {
        return max(0.0, (float) $this->option('long_poll'));
    }

    /**
     * @throws ConnectionException
     */
    private function reportRemoteJob(
        string $serverUrl,
        string $agentId,
        string $secret,
        string $jobId,
        PrintJobStatus $status,
        ?string $error = null
    ): void {
        $payload = [
            'job_id' => $jobId,
            'status' => $status->value,
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders($this->signedHeaders($agentId, $secret, $payload))
            ->post("{$serverUrl}/agents/{$agentId}/report", $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Report HTTP {$response->status()}: {$response->body()}");
        }
    }

    private function signedHeaders(string $agentId, string $secret, array $payload): array
    {
        return [
            'X-Agent-ID' => $agentId,
            'X-Signature' => hash_hmac('sha256', $agentId . ':' . json_encode($payload), $secret),
        ];
    }

    private function resolveAgent(): PrintAgent
    {
        return PrintAgent::query()
            ->when(
                $this->option('agent_id'),
                fn($query, $agentId) => $query->where('agent_id', $agentId)
            )
            ->when(
                $this->option('branch_id'),
                fn($query, $branchId) => $query->where('branch_id', (int) $branchId)
            )
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Deliver bytes already prepared by the server renderer. ESC/POS bytes are
     * intentionally passed unchanged when the configured spooler is raw.
     */
    private function deliver(array $job): void
    {
        $bytes = base64_decode((string) ($job['rendered_bytes'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Print payload is empty or malformed.');
        }

        $printer = (array) ($job['printer'] ?? []);
        match ($printer['type'] ?? null) {
            'spooler' => $this->deliverToSpooler($printer, $bytes),
            'tcp' => $this->deliverToTcp($printer, $bytes),
            'usbRaw' => $this->deliverToUsbRaw($printer, $bytes),
            'bluetooth' => $this->deliverToBluetooth($printer, $bytes),
            default => throw new RuntimeException('This local worker supports spooler, TCP, USB raw, and Bluetooth printers only.'),
        };
    }

    private function deliverToSpooler(array $printer, string $bytes): void
    {
        $name = trim((string) ($this->option('printer_queue') ?: data_get($printer, 'connection.name')));
        if ($name === '') {
            throw new RuntimeException('Spooler printer name is missing.');
        }

        $arguments = ['lp', '-d', $name];
        if ((bool) data_get($printer, 'settings.raw', false)) {
            $arguments = [...$arguments, '-o', 'raw'];
        }

        $copies = max(1, (int) data_get($printer, 'settings.copies', 1));
        if ($copies > 1) {
            $arguments = [...$arguments, '-n', (string) $copies];
        }

        $this->info("Queue name used: {$name}");
        $this->line('lp command: ' . implode(' ', $arguments));

        $timeoutSeconds = max(3, (int) data_get($printer, 'settings.timeout_ms', 10000) / 1000);
        $process = new Process($arguments);
        $process->setInput($bytes);
        $process->setTimeout($timeoutSeconds);
        $process->mustRun();
        $this->line('lp command result: ' . trim($process->getOutput() . $process->getErrorOutput()));

        if (
            (bool) config('printer.spooler.wait_for_completion', false)
            && preg_match('/request id is ([^\s]+)/', $process->getOutput(), $matches) === 1
        ) {
            $this->waitForSpoolerCompletion($name, $matches[1], $timeoutSeconds);
        }
    }

    private function deliverToTcp(array $printer, string $bytes): void
    {
        $host = trim((string) data_get($printer, 'connection.host'));
        $port = (int) data_get($printer, 'connection.port', 9100);
        $timeoutSeconds = max(3, (int) data_get($printer, 'settings.timeout_ms', 5000) / 1000);
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $timeoutSeconds);

        if (! is_resource($socket)) {
            throw new RuntimeException("Printer connection failed: {$errorMessage} ({$errorCode}).");
        }

        stream_set_timeout($socket, $timeoutSeconds);
        $remaining = $bytes;
        while ($remaining !== '') {
            $written = fwrite($socket, $remaining);
            if ($written === false || $written === 0) {
                fclose($socket);
                throw new RuntimeException('Printer connection closed before receipt delivery completed.');
            }
            $remaining = substr($remaining, $written);
        }
        fclose($socket);
    }

    private function deliverToUsbRaw(array $printer, string $bytes): void
    {
        $devicePath = trim((string) data_get($printer, 'connection.device_path'));
        if (! preg_match('#^/dev/(usb/lp[0-9]+|lp[0-9]+)$#', $devicePath)) {
            throw new RuntimeException('USB raw printer device_path must be /dev/usb/lpN or /dev/lpN.');
        }

        if (! is_writable($devicePath)) {
            throw new RuntimeException("USB raw printer device is not writable: {$devicePath}.");
        }

        $copies = max(1, (int) data_get($printer, 'settings.copies', 1));
        $timeoutSeconds = max(3, (int) data_get($printer, 'settings.timeout_ms', 5000) / 1000);
        $this->info("USB raw device used: {$devicePath}");

        for ($copy = 0; $copy < $copies; $copy++) {
            $process = new Process(['timeout', (string) $timeoutSeconds, 'dd', "of={$devicePath}", 'bs=4096', 'status=none']);
            $process->setInput($bytes);
            $process->setTimeout($timeoutSeconds + 1);
            $process->mustRun();
        }
    }

    private function deliverToBluetooth(array $printer, string $bytes): void
    {
        $devicePath = trim((string) data_get($printer, 'connection.device_path', '/dev/rfcomm0'));
        if (! preg_match('#^/dev/rfcomm[0-9]+$#', $devicePath)) {
            throw new RuntimeException('Bluetooth printer device_path must be /dev/rfcommN.');
        }

        if (! file_exists($devicePath)) {
            $macAddress = trim((string) data_get($printer, 'connection.mac_address'));
            $channel = (int) data_get($printer, 'connection.channel', 1);

            if ($macAddress === '' || ! preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $macAddress)) {
                throw new RuntimeException("Bluetooth device {$devicePath} is missing and mac_address is not configured.");
            }

            $bind = new Process(['rfcomm', 'bind', $devicePath, $macAddress, (string) $channel]);
            $bind->setTimeout(5);
            $bind->mustRun();
        }

        if (! is_writable($devicePath)) {
            throw new RuntimeException("Bluetooth printer device is not writable: {$devicePath}.");
        }

        $copies = max(1, (int) data_get($printer, 'settings.copies', 1));
        $timeoutSeconds = max(3, (int) data_get($printer, 'settings.timeout_ms', 10000) / 1000);
        $this->info("Bluetooth device used: {$devicePath}");

        for ($copy = 0; $copy < $copies; $copy++) {
            $process = new Process(['timeout', (string) $timeoutSeconds, 'dd', "of={$devicePath}", 'bs=4096', 'status=none']);
            $process->setInput($bytes);
            $process->setTimeout($timeoutSeconds + 1);
            $process->mustRun();
        }
    }

    private function waitForSpoolerCompletion(string $printerName, string $requestId, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            usleep(250000);
            $status = new Process(['lpstat', '-o', $printerName]);
            $status->setTimeout(3);
            $status->run();

            if (! str_contains($status->getOutput(), $requestId)) {
                return;
            }
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Print spooler did not complete {$requestId} within {$timeoutSeconds} seconds.");
    }
}
