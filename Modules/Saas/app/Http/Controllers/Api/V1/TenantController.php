<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Workspace\TenantWorkspaceService;
use Modules\Saas\Http\Requests\Api\V1\SaveTenantRequest;
use Modules\Saas\Services\Tenant\TenantServiceInterface;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\ResolvesCurrentTenant;
use Modules\Saas\Transformers\Api\V1\TenantResource;
use Modules\Support\ApiResponse;
use Modules\User\Services\Auth\TenantHandoffService;

class TenantController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(protected TenantServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: TenantResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null
        );
    }

    public function registrySummary(): JsonResponse
    {
        return ApiResponse::success($this->service->registrySummary());
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new TenantResource($this->service->show($id)));
    }

    public function checklist(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);

        return ApiResponse::success([
            'completed' => array_values((array) data_get($tenant->settings, 'tenant_admin_checklist.completed', [])),
            'dismissed' => (bool) data_get($tenant->settings, 'tenant_admin_checklist.dismissed', false),
            'updated_at' => data_get($tenant->settings, 'tenant_admin_checklist.updated_at'),
        ]);
    }

    public function updateChecklist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'completed' => ['required', 'array'],
            // The Get Started flow. The original five keys are still accepted so
            // checklists saved before that flow existed keep their progress.
            'completed.*' => ['string', Rule::in(TenantWorkspaceService::CHECKLIST_STEPS)],
            'dismissed' => ['nullable', 'boolean'],
        ]);
        $tenant = $this->currentTenant($request);
        $settings = $tenant->settings ?? [];
        $settings['tenant_admin_checklist'] = [
            'completed' => array_values(array_unique($data['completed'])),
            'dismissed' => (bool) ($data['dismissed'] ?? false),
            'updated_at' => now()->toIso8601String(),
            'updated_by' => $request->user()?->id,
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        activity('tenant_setup')
            ->event('tenant_admin_checklist_updated')
            ->causedBy($request->user())
            ->performedOn($tenant)
            ->withProperties(['completed' => $settings['tenant_admin_checklist']['completed']])
            ->log('Restaurant setup checklist updated.');

        return ApiResponse::success($settings['tenant_admin_checklist'], 'Setup progress saved.');
    }

    public function store(SaveTenantRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new TenantResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveTenantRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new TenantResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function login(Request $request, int $id, TenantHandoffService $handoff): JsonResponse
    {
        $request->validate([
            'frontend_url' => ['nullable', 'url', 'max:255'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $tenant = $this->service->show($id);
        $result = $handoff->issue(
            $tenant,
            $request->string('frontend_url')->toString() ?: null,
            [
                'actor_id' => $request->user()?->id,
                'reason' => $request->string('reason')->toString(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        activity('saas_support_mode')
            ->event('tenant_handoff_issued')
            ->causedBy($request->user())
            ->performedOn($tenant)
            ->withProperties([
                'tenant_id' => $tenant->id,
                'reason' => $request->string('reason')->toString(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'expires_in' => $result['expires_in'],
            ])
            ->log('Tenant support access issued.');

        return ApiResponse::success($result);
    }

    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

}
