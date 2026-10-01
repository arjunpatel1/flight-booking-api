<?php

namespace Modules\Printer\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Printer\Models\PrintJob;

class PrintJobCreated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public PrintJob $job,
        public int|string|null $printerId,
        public string $printType,
        public ?string $targetAgentId = null,
    )
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('agent.' . ($this->targetAgentId ?: data_get($this->job->printer_config, 'agent_id'))),
        ];
    }

    public function broadcastAs(): string
    {
        return PlatformEvent::PRINT_JOB_CREATED;
    }

    public function broadcastWith(): array
    {
        $data = [
            'job_id' => $this->job->id,
            'branch_id' => $this->job->branch_id,
            'printer_id' => $this->printerId,
            'printer_type' => data_get($this->job->printer_config, 'type'),
            'agent_id' => $this->targetAgentId ?: data_get($this->job->printer_config, 'agent_id'),
            'print_type' => $this->printType,
        ];

        return [
            ...PlatformEvent::envelope(
                eventName: PlatformEvent::PRINT_JOB_CREATED,
                entity: 'print_job',
                entityId: $this->job->id,
                branchId: $this->job->branch_id,
                payload: $data,
            ),
            // Legacy keys retained for the Print Agent / current consumers.
            ...$data,
        ];
    }
}
