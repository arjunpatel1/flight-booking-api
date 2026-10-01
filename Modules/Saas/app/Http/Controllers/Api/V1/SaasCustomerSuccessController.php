<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\SaasCustomerSuccessRecord;
use Modules\Saas\Services\CustomerSuccess\CustomerSuccessService;
use Modules\Support\ApiResponse;

class SaasCustomerSuccessController extends Controller
{
    public function index(CustomerSuccessService $service): JsonResponse
    {
        return ApiResponse::success($service->dashboard());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRecord($request);
        $record = SaasCustomerSuccessRecord::query()->create([...$data, 'created_by' => $request->user()?->id]);
        $this->audit($request, $record, 'created');

        return ApiResponse::created($this->payload($record->load(['tenant:id,name', 'assignee:id,name', 'creator:id,name'])), 'Customer Success record created.');
    }

    public function update(Request $request, SaasCustomerSuccessRecord $record): JsonResponse
    {
        $data = $this->validateRecord($request, partial: true);
        if (($data['status'] ?? null) === 'completed' && $record->status !== 'completed') $data['completed_at'] = now();
        if (($data['status'] ?? null) === 'open') $data['completed_at'] = null;
        $record->update($data);
        $this->audit($request, $record, 'updated');

        return ApiResponse::updated($this->payload($record->refresh()->load(['tenant:id,name', 'assignee:id,name', 'creator:id,name'])), 'Customer Success record updated.');
    }

    private function validateRecord(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'tenant_id' => [$sometimes, 'integer', 'exists:tenants,id'],
            'type' => [$sometimes, 'string', 'in:task,note'],
            'visibility' => [$sometimes, 'string', 'in:internal,owner'],
            'title' => [$sometimes, 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'string', 'in:low,normal,medium,high,critical'],
            'status' => ['sometimes', 'string', 'in:open,in_progress,completed,cancelled'],
            'due_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    private function payload(SaasCustomerSuccessRecord $record): array
    {
        return [
            ...$record->only(['id', 'tenant_id', 'type', 'visibility', 'title', 'body', 'priority', 'status']),
            'tenant' => $record->tenant?->name,
            'assigned_to' => $record->assignee?->id,
            'assignee' => $record->assignee?->name,
            'creator' => $record->creator?->name,
            'due_at' => $record->due_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, SaasCustomerSuccessRecord $record, string $event): void
    {
        activity('saas_customer_success')->event("record_{$event}")->causedBy($request->user())->performedOn($record)
            ->withProperties(['tenant_id' => $record->tenant_id, 'type' => $record->type, 'status' => $record->status])
            ->log("Customer Success {$record->type} {$event}.");
    }
}
