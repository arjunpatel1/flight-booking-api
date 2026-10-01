<?php

namespace Modules\Printer\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Printer\Models\PrintJob;

/** @mixin PrintJob */
class PrintJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $diagnostics = (array) data_get($this->printer_config, 'diagnostics', []);

        return [
            'id' => $this->id,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->relationLoaded('branch') ? $this->branch?->name : '',
            ],
            'print_type' => data_get($diagnostics, 'print_type', '-'),
            'order' => [
                'id' => data_get($diagnostics, 'order_id'),
                'reference_no' => data_get($diagnostics, 'reference_no'),
            ],
            'printer_type' => data_get($this->printer_config, 'type'),
            'printer_name' => data_get($this->printer_config, 'connection.name')
                ?: data_get($this->printer_config, 'connection.host')
                ?: data_get($this->printer_config, 'connection.vendor_id')
                ?: '-',
            'agent_id' => data_get($this->printer_config, 'agent_id')
                ?: data_get($diagnostics, 'agent_id'),
            'route_source' => data_get($diagnostics, 'route_source'),
            'paper_size' => data_get($this->printer_config, 'settings.media'),
            'copies' => data_get($this->printer_config, 'settings.copies'),
            'status' => $this->status->toTrans(),
            'failure_reason' => $this->failureReason($diagnostics),
            'pipeline' => $this->pipeline($diagnostics),
            'timeline' => $this->timeline($diagnostics),
            'diagnostics' => $diagnostics,
            'claimed_by' => $this->claimed_by,
            'lease_until' => $this->lease_until ? dateTimeFormat($this->lease_until) : null,
            'error_message' => $this->error_message,
            'completed_at' => $this->completed_at ? dateTimeFormat($this->completed_at) : null,
            'created_at' => dateTimeFormat($this->created_at),
            'updated_at' => dateTimeFormat($this->updated_at),
        ];
    }

    private function failureReason(array $diagnostics): ?string
    {
        if (filled($this->error_message)) {
            return $this->error_message;
        }

        $message = data_get($diagnostics, 'message');

        return filled($message) ? (string) $message : null;
    }

    private function timeline(array $diagnostics): array
    {
        $timeline = data_get($diagnostics, 'timeline', []);

        if (! is_array($timeline)) {
            return [];
        }

        return array_map(function ($entry) {
            $stage = (string) data_get($entry, 'stage', '');
            $key = "printer::print_jobs.stages.{$stage}";
            $label = __($key);

            // Unknown/ad-hoc stages fall back to a humanised version of the raw key.
            $entry['stage_label'] = $label === $key
                ? str($stage)->replace('_', ' ')->title()->value()
                : $label;

            return $entry;
        }, array_values($timeline));
    }

    private function pipeline(array $diagnostics): array
    {
        $checks = data_get($diagnostics, 'checks');
        if (is_array($checks) && ! empty($checks)) {
            return $this->localizeChecks(
                $this->withDeliveryStatus(array_values($checks), $diagnostics),
                $diagnostics,
            );
        }

        $agentId = data_get($this->printer_config, 'agent_id');
        $hasPrinter = data_get($this->printer_config, 'type') !== 'unrouted';

        return [
            [
                'key' => 'route',
                'label' => __('printer::print_jobs.steps.route'),
                'status' => $hasPrinter ? 'ok' : 'failed',
                'message' => $hasPrinter
                    ? __('printer::print_jobs.messages.route_resolved')
                    : __('printer::print_jobs.messages.route_missing'),
            ],
            [
                'key' => 'agent',
                'label' => __('printer::print_jobs.steps.agent'),
                'status' => filled($agentId) ? 'ok' : 'warning',
                'message' => filled($agentId)
                    ? __('printer::print_jobs.messages.agent_selected', ['agent' => $agentId])
                    : __('printer::print_jobs.messages.agent_missing'),
            ],
            $this->deliveryCheck($diagnostics),
        ];
    }

    /**
     * Localise the diagnostics checks at display time. Labels are re-mapped by
     * their stable key and the deterministic messages by (key + status), using
     * the diagnostics context for placeholders. Anything dynamic (queue history,
     * failure reasons) keeps its stored message. Works for old and new rows
     * alike — no persisted-format change required.
     */
    private function localizeChecks(array $checks, array $diagnostics): array
    {
        $ref = data_get($diagnostics, 'reference_no');
        $agentId = $this->claimed_by
            ?: data_get($this->printer_config, 'agent_id')
            ?: data_get($diagnostics, 'agent_id');

        $labelledKeys = ['order', 'route', 'agent', 'queue', 'delivery'];

        foreach ($checks as $index => $check) {
            $key = (string) data_get($check, 'key', '');
            $status = (string) data_get($check, 'status', '');

            if (in_array($key, $labelledKeys, true)) {
                $checks[$index]['label'] = __("printer::print_jobs.steps.{$key}");
            }

            if ($key === 'delivery') {
                continue; // already localised by deliveryCheck()
            }

            $message = match ("{$key}:{$status}") {
                'order:ok' => __('printer::print_jobs.messages.check_order_ok', ['ref' => $ref]),
                'route:ok' => __('printer::print_jobs.messages.route_resolved'),
                'route:failed' => __('printer::print_jobs.messages.route_no_match'),
                'agent:ok' => __('printer::print_jobs.messages.agent_selected', ['agent' => $agentId]),
                'agent:warning' => __('printer::print_jobs.messages.agent_missing'),
                'agent:failed' => __('printer::print_jobs.messages.agent_none'),
                default => null,
            };

            if ($message !== null) {
                $checks[$index]['message'] = $message;
            }
        }

        return $checks;
    }

    private function withDeliveryStatus(array $checks, array $diagnostics): array
    {
        $delivery = $this->deliveryCheck($diagnostics);
        $hasDelivery = false;

        foreach ($checks as $index => $check) {
            if (($check['key'] ?? null) === 'delivery') {
                $checks[$index] = $delivery;
                $hasDelivery = true;
                break;
            }
        }

        if (! $hasDelivery) {
            $checks[] = $delivery;
        }

        return $checks;
    }

    private function deliveryCheck(array $diagnostics): array
    {
        $agentId = $this->claimed_by
            ?: data_get($this->printer_config, 'agent_id')
            ?: data_get($diagnostics, 'agent_id');
        $isPending = $this->status->value === 'pending';

        return [
            'key' => 'delivery',
            'label' => __('printer::print_jobs.steps.delivery'),
            'status' => match (true) {
                $this->status->value === 'success' => 'ok',
                $this->status->value === 'failed' => 'failed',
                $isPending && filled($this->claimed_by) => 'warning',
                default => 'pending',
            },
            'message' => match (true) {
                $this->status->value === 'success' => __('printer::print_jobs.messages.delivery_success'),
                $this->status->value === 'failed' => $this->failureReason($diagnostics) ?: __('printer::print_jobs.messages.delivery_failed'),
                $isPending && filled($this->claimed_by) => __('printer::print_jobs.messages.delivery_claimed', ['agent' => $this->claimed_by]),
                filled($agentId) => __('printer::print_jobs.messages.delivery_waiting_agent', ['agent' => $agentId]),
                default => __('printer::print_jobs.messages.delivery_waiting_any'),
            },
        ];
    }
}
