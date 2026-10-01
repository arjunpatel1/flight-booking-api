<?php

namespace Modules\Notification\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Notification\Models\WhatsAppLog;

class RetryFailedWhatsAppMessages extends Command
{
    protected $signature = 'whatsapp:retry-failed
                            {--limit=100 : Number of failed messages to retry}
                            {--max-attempts=3 : Maximum retry attempts}';

    protected $description = 'Retry failed WhatsApp messages';

    public function handle(): void
    {
        $limit = (int) $this->option('limit');
        $maxAttempts = (int) $this->option('max-attempts');

        $failedLogs = WhatsAppLog::retryable()
            ->where('failed_attempts', '<', $maxAttempts)
            ->limit($limit)
            ->get();

        if ($failedLogs->isEmpty()) {
            $this->info('No failed messages to retry');
            return;
        }

        $this->info("Retrying {$failedLogs->count()} failed WhatsApp messages...");
        $progressBar = $this->output->createProgressBar($failedLogs->count());

        foreach ($failedLogs as $log) {
            SendWhatsAppMessageJob::dispatch(
                $log->recipient,
                $log->template,
                $log->retry_payload['parameters'] ?? [],
                [
                    'tenant_id' => $log->tenant_id,
                    'branch_id' => $log->branch_id,
                    'audience' => data_get($log->request_payload, 'audience'),
                    'campaign_id' => data_get($log->request_payload, 'campaign_id'),
                ],
            );

            $log->update([
                'failed_attempts' => ($log->failed_attempts ?? 0) + 1,
                'status' => NotificationStatus::Processing,
            ]);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info('✓ Retry job queued successfully');
    }
}
