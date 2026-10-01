<?php

namespace Modules\Dashboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Dashboard\Services\Dashboard\DashboardServiceInterface;

class GenerateAiInsightSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly ?int $generatedBy = null)
    {
        $this->onQueue('analytics');
    }

    public function handle(DashboardServiceInterface $service): void
    {
        $service->storeSmartInsightSnapshot($this->generatedBy);
    }
}
