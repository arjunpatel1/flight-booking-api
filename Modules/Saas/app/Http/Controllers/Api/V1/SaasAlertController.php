<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Operations\SaasAlertStateService;
use Modules\Support\ApiResponse;

class SaasAlertController extends Controller
{
    public function assignToMe(Request $request, string $fingerprint, SaasAlertStateService $alerts): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $actorId = (int) $request->user()?->id;
        abort_unless($actorId, 403, 'An authenticated operator is required to assign an alert.');
        $state = $alerts->assignTo($fingerprint, $actorId, $data['note'] ?? null);

        activity('saas_alert_center')
            ->event('alert_assigned')
            ->causedBy($request->user())
            ->withProperties(['fingerprint' => $fingerprint, 'assigned_to' => $actorId, 'note' => $data['note'] ?? null])
            ->log('SaaS alert assigned to operator.');

        return ApiResponse::updated($state, 'Alert assigned to you.');
    }

    public function acknowledge(Request $request, string $fingerprint, SaasAlertStateService $alerts): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $state = $alerts->acknowledge($fingerprint, $request->user()?->id, $data['note'] ?? null);

        activity('saas_alert_center')
            ->event('alert_acknowledged')
            ->causedBy($request->user())
            ->withProperties(['fingerprint' => $fingerprint, 'note' => $data['note'] ?? null])
            ->log('SaaS alert acknowledged.');

        return ApiResponse::updated($state, 'Alert acknowledged.');
    }

    public function resolve(Request $request, string $fingerprint, SaasAlertStateService $alerts): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $state = $alerts->resolve($fingerprint, $request->user()?->id, $data['note'] ?? null);

        activity('saas_alert_center')
            ->event('alert_resolved')
            ->causedBy($request->user())
            ->withProperties(['fingerprint' => $fingerprint, 'note' => $data['note'] ?? null])
            ->log('SaaS alert resolved.');

        return ApiResponse::updated($state, 'Alert resolved.');
    }
}
