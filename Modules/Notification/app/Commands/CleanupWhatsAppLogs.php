<?php

namespace Modules\Notification\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Models\WhatsAppLog;

class CleanupWhatsAppLogs extends Command
{
    protected $signature = 'whatsapp:cleanup-logs
                            {--days=30 : Days to keep logs}
                            {--only-failed : Only clean failed messages}';

    protected $description = 'Clean old WhatsApp logs to free up space';

    public function handle(): void
    {
        $days = (int) $this->option('days');
        $onlyFailed = $this->option('only-failed');

        $query = WhatsAppLog::where('created_at', '<', now()->subDays($days));

        if ($onlyFailed) {
            $query->where('status', 'failed');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No logs to clean');
            return;
        }

        if (!$this->confirm("Delete {$count} WhatsApp logs older than {$days} days?")) {
            return;
        }

        $query->delete();
        $this->info("✓ Deleted {$count} WhatsApp logs");
    }
}
