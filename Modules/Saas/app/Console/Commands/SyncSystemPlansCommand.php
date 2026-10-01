<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\SubscriptionPlan;

class SyncSystemPlansCommand extends Command
{
    protected $signature = 'saas:sync-system-plans
        {--dry-run : Report changes without updating plans}';

    protected $description = 'Add required baseline features to NexDine-generated subscription plans without changing custom plans.';

    public function handle(): int
    {
        $required = collect(config('saas.default_features', []))
            ->filter()
            ->unique()
            ->values();

        $plans = SubscriptionPlan::query()
            ->withoutGlobalScopes()
            ->where('description', 'Default SaaS starter plan.')
            ->get();

        $updated = 0;

        foreach ($plans as $plan) {
            $current = collect($plan->features ?? [])->filter()->unique()->values();
            $missing = $required->diff($current)->values();

            if ($missing->isEmpty()) {
                $this->line("{$plan->name}: already current.");
                continue;
            }

            $prefix = $this->option('dry-run') ? '[dry-run] ' : '';
            $this->info("{$prefix}{$plan->name}: add {$missing->implode(', ')}.");

            if (! $this->option('dry-run')) {
                $plan->forceFill([
                    'features' => $current->merge($missing)->unique()->values()->all(),
                ])->save();
            }

            $updated++;
        }

        $verb = $this->option('dry-run') ? 'would be updated' : 'updated';
        $this->info("System plan synchronization complete: {$updated} plan(s) {$verb}.");

        return self::SUCCESS;
    }
}
