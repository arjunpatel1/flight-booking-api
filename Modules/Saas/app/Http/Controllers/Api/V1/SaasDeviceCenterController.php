<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Models\Printer;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Devices\SaasDeviceCenterService;
use Modules\Support\ApiResponse;

class SaasDeviceCenterController extends Controller
{
    public function index(SaasDeviceCenterService $service): JsonResponse
    {
        return ApiResponse::success($service->overview());
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:pos_terminal,print_agent'],
            'device_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $branch = Branch::query()
            ->withoutGlobalScopes()
            ->whereKey($data['branch_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $device = $data['type'] === 'pos_terminal'
            ? PosTerminalDevice::query()->withoutGlobalScopes()->findOrFail($data['device_id'])
            : PrintAgent::query()->withoutGlobalScopes()->findOrFail($data['device_id']);
        $previous = (int) $device->branch_id;
        $releasedJobs = 0;

        DB::transaction(function () use ($data, $device, $previous, &$releasedJobs) {
            if ($data['type'] === 'print_agent' && $previous !== (int) $data['branch_id']) {
                // Printers discovered at the old location must remain there for
                // audit/history, but they can no longer be eligible for routing.
                Printer::query()
                    ->withoutGlobalScopes()
                    ->where('branch_id', $previous)
                    ->where('options->agent_id', $device->agent_id)
                    ->update(['is_active' => false]);

                // Return unfinished old-branch work to that branch instead of
                // leaving it permanently leased to the moved Windows agent.
                PrintJob::query()
                    ->withoutGlobalScopes()
                    ->where('branch_id', $previous)
                    ->where('status', PrintJobStatus::Pending)
                    ->where(function ($query) use ($device) {
                        $query->where('claimed_by', $device->agent_id)
                            ->orWhere('printer_config->agent_id', $device->agent_id);
                    })
                    ->get()
                    ->each(function (PrintJob $job) use (&$releasedJobs) {
                        $printerConfig = $job->printer_config ?? [];
                        data_set($printerConfig, 'agent_id', null);
                        $job->forceFill([
                            'printer_config' => $printerConfig,
                            'claimed_by' => null,
                            'lease_until' => null,
                        ])->save();
                        $releasedJobs++;
                    });
                $device->update(['branch_id' => $data['branch_id']]);

                return;
            }

            if ($data['type'] === 'pos_terminal' && $previous !== (int) $data['branch_id']) {
                $meta = $device->meta ?? [];
                data_set($meta, 'assignment', [
                    'pending_claim' => true,
                    'previous_branch_id' => $previous,
                    'branch_id' => (int) $data['branch_id'],
                    'assigned_at' => now()->toISOString(),
                ]);
                $device->update([
                    'branch_id' => $data['branch_id'],
                    'pos_register_id' => null,
                    'pos_session_id' => null,
                    'created_by' => null,
                    'status' => 'offline',
                    'last_seen_at' => null,
                    'meta' => $meta,
                ]);

                return;
            }

            $device->update(['branch_id' => $data['branch_id']]);
        });

        if ($data['type'] === 'print_agent') {
            Cache::forget(AgentPollService::emptyPollCacheKey($previous, $device->agent_id));
            Cache::forget(AgentPollService::emptyPollCacheKey((int) $data['branch_id'], $device->agent_id));
        }

        activity('saas_devices')->event('device_assigned')->causedBy($request->user())->performedOn($device)
            ->withProperties([
                'type' => $data['type'],
                'previous_branch_id' => $previous,
                'branch_id' => $data['branch_id'],
                'tenant_id' => $branch->tenant_id,
                'released_print_jobs' => $releasedJobs,
                'reason' => $data['reason'],
            ])
            ->log('Device assigned from SaaS Device Center.');

        return ApiResponse::updated([
            'id' => $device->id,
            'branch_id' => $device->branch_id,
            'tenant_id' => $branch->tenant_id,
            'released_print_jobs' => $releasedJobs,
            'configuration_sync' => match ($data['type']) {
                'print_agent' => 'automatic_on_next_heartbeat',
                'pos_terminal' => $previous !== (int) $data['branch_id'] ? 'pending_login_at_assigned_branch' : null,
            },
        ], 'Device assignment updated.');
    }

    public function renameTerminal(Request $request, int $device): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);
        $terminal = PosTerminalDevice::query()->withoutGlobalScopes()->findOrFail($device);
        $previousName = $terminal->name;
        $terminal->update(['name' => trim($data['name'])]);

        activity('saas_devices')->event('terminal_renamed')->causedBy($request->user())->performedOn($terminal)
            ->withProperties(['previous_name' => $previousName, 'name' => $terminal->name])
            ->log('Terminal friendly name updated.');

        return ApiResponse::updated(['device' => $terminal->toStatusPayload()], 'Terminal name updated.');
    }
}
