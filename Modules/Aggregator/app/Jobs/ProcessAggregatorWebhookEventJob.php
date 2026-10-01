<?php

namespace Modules\Aggregator\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Aggregator\Services\Webhook\AggregatorWebhookServiceInterface;

class ProcessAggregatorWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $eventId)
    {
        $this->onQueue('aggregator-webhooks');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        app(AggregatorWebhookServiceInterface::class)->process($this->eventId);
    }
}
