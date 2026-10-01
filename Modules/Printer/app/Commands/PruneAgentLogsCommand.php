<?php

namespace Modules\Printer\Commands;

use Illuminate\Console\Command;
use Modules\Printer\Models\AgentLog;

class PruneAgentLogsCommand extends Command
{
    protected $signature   = 'printer:prune-agent-logs {--days= : Override default retention window}';
    protected $description = 'Delete agent log entries older than the retention window (default 7 days).';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('printer.agent_log_retention_days', 7));

        if ($days < 1) {
            $this->error('Retention window must be at least 1 day.');
            return self::FAILURE;
        }

        $cutoff  = now()->subDays($days);
        $deleted = AgentLog::query()->where('logged_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} agent log entries older than {$days} days (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
