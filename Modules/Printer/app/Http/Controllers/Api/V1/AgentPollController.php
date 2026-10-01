<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Controllers\Controller;
use Modules\Core\Events\PlatformEvent;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Models\AgentCommand;
use Modules\Printer\Models\AgentIncident;
use Modules\Voice\Models\VoiceAlert;
use Modules\Voice\Models\VoiceDiagnosticRun;
use Modules\Voice\Models\VoiceExportBundle;
use Modules\Voice\Models\VoiceHealthSnapshot;
use Modules\Voice\Models\VoiceHistory;
use Modules\Voice\Models\VoiceTestResult;
use Modules\Printer\Models\AgentLog;
use Modules\Printer\Models\AgentTelemetry;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Models\Printer;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use Modules\Printer\Services\Reverb\ReverbConfigService;
use Throwable;

class AgentPollController extends Controller
{
    use HandlesAgentDiagnostics;
    use HandlesAgentPrinterInventory;
    use HandlesAgentSetupAndTestPrint;
    use HandlesAgentVoiceReports;

    /**
     * Create a new instance of AgentPollController
     *
     * @param AgentPollServiceInterface $service
     */
    public function __construct(protected AgentPollServiceInterface $service)
    {
    }

    /**
     * Poll
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function poll(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        try {
            Log::debug('Print agent poll started.', [
                'agent_id' => $request->agent->agent_id,
                'branch_id' => $request->input('branch_id'),
                'wait_seconds' => $request->input('wait_seconds'),
                'delivery_source' => $request->input('delivery_source', 'polling'),
                ...$this->agentRequestContext($request),
            ]);

            $waitSeconds = $this->longPollSeconds($request);
            $deadline = microtime(true) + $waitSeconds;

            do {
                // The signed agent record is the source of truth. This lets SaaS
                // Admin move an agent to another restaurant branch without the
                // stale local branch value blocking every poll.
                $jobs = $this->service->poll($request->agent, (int) $request->agent->branch_id);
                $voiceAnnouncements = $this->pollVoiceAnnouncements($request);

                if ($jobs->isNotEmpty() || $voiceAnnouncements->isNotEmpty() || $waitSeconds <= 0) {
                    break;
                }

                usleep($this->longPollIntervalMicroseconds());
            } while (microtime(true) < $deadline);

            $logContext = [
                'agent_id' => $request->agent->agent_id,
                'branch_id' => $request->agent->branch_id,
                'jobs_count' => $jobs->count(),
                'voice_announcements_count' => $voiceAnnouncements->count(),
                'job_ids' => $jobs->pluck('job_id')->values()->all(),
                'voice_announcement_ids' => $voiceAnnouncements->pluck('announcement_id')->values()->all(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'delivery_source' => $request->input('delivery_source', 'polling'),
                ...$this->agentRequestContext($request),
            ];

            if ($jobs->isNotEmpty() || $voiceAnnouncements->isNotEmpty()) {
                Log::info('Print agent poll finished with work.', $logContext);
            } else {
                Log::debug('Print agent poll finished with no work.', $logContext);
            }

            $hasWork = $jobs->isNotEmpty() || $voiceAnnouncements->isNotEmpty();

            return response()
                ->json([
                    'jobs' => $jobs,
                    'voice_announcements' => $voiceAnnouncements,
                    'retry_after' => $this->retryAfterSeconds(! $hasWork, $waitSeconds),
                ]);
        } catch (Exception $exception) {
            Log::warning('Print agent poll failed.', [
                'agent_id' => optional($request->agent)->agent_id,
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['error' => $exception->getMessage()], 403);
        }
    }

    public function job(Request $request, string $agentId, string $jobId): JsonResponse
    {
        $startedAt = microtime(true);

        try {
            $job = $this->service->fetchJob(
                $request->agent,
                $jobId,
                (int) $request->agent->branch_id
            );

            Log::info('Print job fetched by agent.', [
                'agent_id' => $request->agent->agent_id,
                'branch_id' => $request->agent->branch_id,
                'job_id' => $jobId,
                'printer_type' => data_get($job, 'printer.type'),
                'printer_name' => data_get($job, 'printer.connection.name') ?: data_get($job, 'printer.connection.host'),
                'delivery_source' => $request->input('delivery_source', 'unknown'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ...$this->agentRequestContext($request),
            ]);

            return response()->json(['job' => $job]);
        } catch (Exception $exception) {
            Log::warning('Print job fetch failed.', [
                'agent_id' => $agentId,
                'job_id' => $jobId,
                'delivery_source' => $request->input('delivery_source', 'unknown'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['error' => $exception->getMessage()], 403);
        }
    }

    public function jobStatus(Request $request, string $agentId, string $jobId): JsonResponse
    {
        $job = PrintJob::query()
            ->where('id', $jobId)
            ->where('branch_id', $request->agent->branch_id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        if (filled(data_get($job->printer_config, 'agent_id')) && data_get($job->printer_config, 'agent_id') !== $request->agent->agent_id) {
            return response()->json(['error' => 'Job is assigned to another agent.'], 403);
        }

        return response()->json([
            'job_id' => $job->id,
            'status' => $job->status->value,
            'claimed_by' => $job->claimed_by,
            'lease_until' => optional($job->lease_until)->toISOString(),
            'completed_at' => optional($job->completed_at)->toISOString(),
            'error_message' => $job->error_message,
        ]);
    }

    private function longPollSeconds(Request $request): float
    {
        $requestedSeconds = $request->has('wait_seconds')
            ? (float) $request->input('wait_seconds', 0)
            : (float) config('printer.agent.default_poll_wait_seconds', 20.0);
        $maxSeconds = max(0.0, (float) config('printer.agent.long_poll_seconds', 20.0));

        return min(max(0.0, $requestedSeconds), $maxSeconds);
    }

    private function longPollIntervalMicroseconds(): int
    {
        $milliseconds = max(100, (int) config('printer.agent.long_poll_interval_ms', 250));

        return $milliseconds * 1000;
    }

    private function retryAfterSeconds(bool $isEmpty, float $waitSeconds): float
    {
        if (! $isEmpty) {
            return 0.0;
        }

        if ($waitSeconds > 0) {
            return max(0.0, (float) config('printer.agent.long_poll_retry_after_seconds', 0.2));
        }

        return (float) config('printer.agent.idle_sleep_seconds', 60.0);
    }

    /**
     * Report
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function report(Request $request)
    {
        $startedAt = microtime(true);

        try {
            $this->service->report(
                $request->agent,
                $request->job_id,
                PrintJobStatus::from($request->status),
                $request->error
            );
            Log::info('Print job report received.', [
                'agent_id' => $request->agent->agent_id,
                'job_id' => $request->job_id,
                'status' => $request->status,
                'error' => $request->error,
                'delivery_source' => $request->input('delivery_source', 'unknown'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ...$this->agentRequestContext($request),
            ]);
        } catch (Exception $exception) {
            Log::warning('Print job report failed.', [
                'agent_id' => optional($request->agent)->agent_id,
                'job_id' => $request->job_id,
                'status' => $request->status,
                'delivery_source' => $request->input('delivery_source', 'unknown'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['error' => $exception->getMessage()], 403);
        }

        return response()->json(['success' => true]);
    }

    public function broadcastingAuth(Request $request): JsonResponse
    {
        try {
            $socketId = (string) $request->input('socket_id');
            $channelName = (string) $request->input('channel_name');
            $agentId = (string) data_get($request->agent, 'agent_id', '');
            $branchId = data_get($request->agent, 'branch_id');

            // The agent listens on its own print channel AND its branch channel
            // (server-triggered voice announcements broadcast on private-branch.{id}).
            $allowedChannels = array_filter([
                $agentId !== '' ? 'private-agent.' . $agentId : null,
                filled($branchId) ? 'private-branch.' . $branchId : null,
            ]);

            if ($socketId === '' || ! in_array($channelName, $allowedChannels, true)) {
                throw ValidationException::withMessages([
                    'channel_name' => __('validation.invalid', ['attribute' => 'channel_name']),
                ]);
            }

            $key = (string) config('broadcasting.connections.reverb.key');
            $secret = (string) config('broadcasting.connections.reverb.secret');

            if ($key === '' || $secret === '') {
                throw new Exception('Reverb credentials are not configured.');
            }

            $signature = hash_hmac('sha256', "{$socketId}:{$channelName}", $secret);

            return response()->json([
                'auth' => "{$key}:{$signature}",
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            return response()->json(['error' => $exception->getMessage()], 403);
        }
    }

    private function agentRequestContext(Request $request): array
    {
        return [
            'client' => (string) $request->header('X-Agent-Client', ''),
            'user_agent' => substr((string) $request->userAgent(), 0, 160),
            'ip' => $request->ip(),
        ];
    }
}
