<?php

namespace Modules\Printer\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Services\Reverb\ReverbConfigService;

/** @mixin PrintAgent */
class PrintAgentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $agent = $this->resource;
        $value = static fn (string $key, mixed $default = null): mixed => data_get($agent, $key, $default);
        $branchName = method_exists($agent, 'relationLoaded') && $agent->relationLoaded("branch")
            ? data_get($agent, 'branch.name', '')
            : "";

        return [
            "id"           => $value('id'),
            "name"         => $value('name'),
            "agent_id"     => $value('agent_id'),
            "branch"       => [
                "id"   => $value('branch_id'),
                "name" => $branchName,
            ],
            "secret"       => $value('secret'),
            "setup"        => $this->setup($request),
            "is_active"    => $value('is_active'),
            // Health & telemetry fields (populated by agent heartbeat)
            "status"                => $value('status'),
            "version"               => $value('version'),
            "platform"              => $value('platform'),
            "machine_name"          => $value('machine_name'),
            "queue_status"          => $value('queue_status'),
            "printer_inventory"     => $value('printer_inventory'),
            "health_payload"        => $value('health_payload'),
            "last_error"            => $value('last_error'),
            "last_print_success_at" => dateTimeFormat($value('last_print_success_at')),
            "last_print_failed_at"  => dateTimeFormat($value('last_print_failed_at')),
            "last_seen_at"          => dateTimeFormat($value('last_seen_at')),
            "created_at"            => dateTimeFormat($value('created_at')),
            "updated_at"            => dateTimeFormat($value('updated_at')),
        ];
    }

    private function setup(Request $request): array
    {
        $apiV1Url = rtrim($request->getSchemeAndHttpHost(), '/') . '/api/v1';
        $agentId = (string) $this->agent_id;
        $reverb = $this->reverbSetup($request);

        return [
            'server_url' => $apiV1Url,
            'agent_id' => $agentId,
            'agent_secret' => $this->secret,
            'branch_id' => (string) $this->branch_id,
            'mode' => $this->effectiveTransport(),
            'websocket_channel' => "private-agent.{$agentId}",
            'websocket_event' => 'print.job.created',
            'reverb_app_key' => $reverb['app_key'],
            'reverb_socket_url' => $reverb['socket_url'],
            'urls' => [
                'poll' => "{$apiV1Url}/agents/{$agentId}/poll",
                'fetch_job' => "{$apiV1Url}/agents/{$agentId}/jobs/{job_id}",
                'report' => "{$apiV1Url}/agents/{$agentId}/report",
                'heartbeat' => "{$apiV1Url}/agents/{$agentId}/heartbeat",
                'broadcast_auth' => "{$apiV1Url}/agents/{$agentId}/broadcasting/auth",
                'windows_package' => "{$apiV1Url}/print-agents/scripts/windows-zip",
                'windows_installer_script' => "{$apiV1Url}/print-agents/scripts/windows",
                'windows_agent_script' => "{$apiV1Url}/print-agents/scripts/windows-agent",
                'ubuntu_installer_script' => "{$apiV1Url}/print-agents/scripts/ubuntu",
                'ubuntu_agent_script' => "{$apiV1Url}/print-agents/scripts/ubuntu-agent",
            ],
        ];
    }

    private function reverbSetup(Request $request): array
    {
        return (new ReverbConfigService())->toArray($request);
    }

    private function effectiveTransport(): string
    {
        if (
            config('printer.agent.transport', 'polling') === 'reverb'
            || (
                filled(config('broadcasting.connections.reverb.key'))
                && filled(config('broadcasting.connections.reverb.secret'))
            )
        ) {
            return 'reverb';
        }

        return 'polling';
    }
}
