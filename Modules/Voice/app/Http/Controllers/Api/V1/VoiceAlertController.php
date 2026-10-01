<?php

namespace Modules\Voice\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Voice\Models\VoiceAlert;

class VoiceAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', 'string', 'in:' . implode(',', VoiceAlert::STATUSES)],
            'type'     => ['nullable', 'string', 'in:' . implode(',', VoiceAlert::ALERT_TYPES)],
            'severity' => ['nullable', 'string', 'in:' . implode(',', VoiceAlert::SEVERITIES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = VoiceAlert::query()
            ->withoutGlobalBranchPermission()
            ->orderByDesc('fired_at');

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('type'))     $query->where('type', $request->type);
        if ($request->filled('severity')) $query->where('severity', $request->severity);

        $alerts = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data'       => $alerts->items(),
            'pagination' => [
                'total'        => $alerts->total(),
                'per_page'     => $alerts->perPage(),
                'current_page' => $alerts->currentPage(),
                'last_page'    => $alerts->lastPage(),
            ],
        ]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $alert = VoiceAlert::withoutGlobalBranchPermission()->findOrFail($id);

        if ($alert->status === 'resolved') {
            return response()->json(['message' => 'Alert is already resolved'], 422);
        }

        $alert->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $alert->fresh()]);
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $alert = VoiceAlert::withoutGlobalBranchPermission()->findOrFail($id);

        if ($alert->status !== 'active') {
            return response()->json(['message' => 'Only active alerts can be acknowledged'], 422);
        }

        $alert->update(['status' => 'acknowledged']);

        return response()->json(['data' => $alert->fresh()]);
    }

    public function activeCount(): JsonResponse
    {
        $count = VoiceAlert::query()
            ->withoutGlobalBranchPermission()
            ->active()
            ->count();

        return response()->json(['count' => $count]);
    }
}
