<?php

namespace Modules\Voice\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use Modules\Voice\Services\VoiceAnnouncementService;
use Modules\Voice\Models\VoiceSetting;
use Modules\Voice\Models\VoiceTemplate;
use Modules\Voice\Models\VoiceHistory;
use Modules\Voice\Http\Requests\SaveVoiceSettingsRequest;
use Modules\Voice\Http\Requests\SaveVoiceTemplateRequest;
use Modules\Voice\Http\Requests\TestVoiceRequest;
use Modules\Voice\Http\Requests\TriggerAnnouncementRequest;
use Modules\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VoiceController extends Controller
{
    public function __construct(
        private VoiceAnnouncementService $voiceService
    ) {
    }

    /**
     * Get voice settings for the current branch.
     */
    public function getSettings(): JsonResponse
    {
        try {
            $branchId = $this->resolveBranchId();
            if (!$branchId) {
                return $this->branchUnavailableResponse();
            }

            $settings = $this->voiceService->getSettings($branchId);

            if (!$settings) {
                $settings = VoiceSetting::create(VoiceSetting::getDefaultSettings($branchId));
            }

            return ApiResponse::success($settings);
        } catch (\Exception $e) {
            Log::error('Failed to get voice settings: ' . $e->getMessage());
            return ApiResponse::errors(null, 'Failed to retrieve voice settings', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save voice settings for the current branch.
     */
    public function saveSettings(SaveVoiceSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }

        $settings = $this->voiceService->saveSettings($branchId, $validated);

        return ApiResponse::success($settings, 'Voice settings saved successfully');
    }

    /**
     * Get voice templates for the current branch.
     */
    public function getTemplates(): JsonResponse
    {
        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }

        $templates = $this->voiceService->getTemplates($branchId);

        return ApiResponse::success($templates);
    }

    /**
     * Save voice template for the current branch.
     */
    public function saveTemplate(SaveVoiceTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }

        $template = $this->voiceService->saveTemplate($branchId, $validated);

        return ApiResponse::success($template, 'Voice template saved successfully');
    }

    /**
     * Delete voice template.
     */
    public function deleteTemplate(int $templateId): JsonResponse
    {
        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }

        $template = VoiceTemplate::where('id', $templateId)
            ->where('branch_id', $branchId)
            ->first();

        if (!$template) {
            return ApiResponse::errors(null, 'Template not found', Response::HTTP_NOT_FOUND);
        }

        if (!auth()->user()?->can('admin.voice.templates.edit')) {
            return ApiResponse::errors(null, 'Forbidden', Response::HTTP_FORBIDDEN);
        }

        $this->voiceService->deleteTemplate($templateId, $branchId);

        return response()->json(null, 204);
    }

    /**
     * Get voice history for the current branch.
     */
    public function getHistory(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }

        $validated = $request->validate([
            'event_type' => ['sometimes', 'nullable', 'string', Rule::in(VoiceTemplate::EVENT_TYPES)],
            'success' => 'sometimes|nullable|boolean',
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date|after_or_equal:from',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $history = $this->voiceService->getHistory($branchId, $validated);

        return ApiResponse::pagination($history);
    }

    /**
     * Test voice announcement.
     */
    public function testVoice(TestVoiceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $branchId = $this->resolveBranchId();
            if (!$branchId) {
                return $this->branchUnavailableResponse();
            }

            $success = $this->voiceService->triggerCustomAnnouncement($branchId, null, $validated['message'], 'TestVoice');

            if ($success) {
                return ApiResponse::success(null, 'Voice test announcement triggered successfully');
            }

            return ApiResponse::errors(null, 'Failed to trigger voice test announcement. Please check voice settings and try again.', Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            Log::error('Voice test failed: ' . $e->getMessage());
            return ApiResponse::errors(null, 'An error occurred while testing voice announcement', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Trigger voice announcement for an order.
     */
    public function triggerAnnouncement(TriggerAnnouncementRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $branchId = $this->resolveBranchId();
            if (!$branchId) {
                return $this->branchUnavailableResponse();
            }

            $variables = $request->input('variables', []);

            $success = $this->voiceService->triggerAnnouncement(
                $branchId,
                $validated['order_id'] ?? null,
                $validated['event_type'],
                $variables
            );

            if ($success) {
                return ApiResponse::success(null, 'Voice announcement triggered successfully');
            }

            return ApiResponse::errors(null, 'Failed to trigger voice announcement. Please check voice settings and ensure voice is enabled.', Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            Log::error('Voice announcement trigger failed: ' . $e->getMessage());
            return ApiResponse::errors(null, 'An error occurred while triggering voice announcement', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get available audio devices.
     */
    public function getDevices(): JsonResponse
    {
        return ApiResponse::success([
            'devices' => [],
            'total' => 0,
        ]);
    }

    /**
     * Bulk save templates for a branch.
     */
    public function bulkSaveTemplates(Request $request): JsonResponse
    {
        // Rate limiting: 10 requests per minute per user
        $response = RateLimiter::attempt(
            'bulk-save-templates:' . auth()->id(),
            10,
            function () use ($request) {
                try {
                    $branchId = $this->resolveBranchId();
                    if (!$branchId) {
                        return $this->branchUnavailableResponse();
                    }

                    $validated = $request->validate([
                        'templates' => 'required|array|max:50',
                        'templates.*.id' => [
                            'sometimes',
                            'nullable',
                            'integer',
                            Rule::exists('voice_templates', 'id')->where('branch_id', $branchId),
                        ],
                        'templates.*.template_name' => 'required|string|max:100',
                        'templates.*.template_text' => 'required|string|max:1000',
                        'templates.*.event_type' => ['required', 'string', Rule::in(VoiceTemplate::EVENT_TYPES)],
                        'templates.*.is_default' => 'sometimes|boolean',
                        'templates.*.priority' => 'sometimes|integer|min:0|max:2',
                        'templates.*.is_active' => 'sometimes|boolean',
                    ]);

                    $savedTemplates = $this->voiceService->bulkSaveTemplates($branchId, $validated['templates']);

                    return ApiResponse::success([
                        'saved' => count($savedTemplates),
                        'templates' => $savedTemplates,
                    ], 'Bulk save completed successfully');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    Log::error('Bulk save templates failed: ' . $e->getMessage());
                    return ApiResponse::errors(null, 'Failed to bulk save templates', Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            },
            60
        );

        return $response instanceof JsonResponse
            ? $response
            : ApiResponse::errors(null, 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Bulk delete templates for a branch.
     */
    public function bulkDeleteTemplates(Request $request): JsonResponse
    {
        // Rate limiting: 10 requests per minute per user
        $response = RateLimiter::attempt(
            'bulk-delete-templates:' . auth()->id(),
            10,
            function () use ($request) {
                try {
                    $branchId = $this->resolveBranchId();
                    if (!$branchId) {
                        return $this->branchUnavailableResponse();
                    }

                    $validated = $request->validate([
                        'template_ids' => 'required|array|max:50',
                        'template_ids.*' => [
                            'integer',
                            Rule::exists('voice_templates', 'id')->where('branch_id', $branchId),
                        ],
                    ]);

                    $deletedCount = $this->voiceService->bulkDeleteTemplates($branchId, $validated['template_ids']);

                    return ApiResponse::success([
                        'deleted' => $deletedCount,
                    ], 'Bulk delete completed successfully');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    Log::error('Bulk delete templates failed: ' . $e->getMessage());
                    return ApiResponse::errors(null, 'Failed to bulk delete templates', Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            },
            60
        );

        return $response instanceof JsonResponse
            ? $response
            : ApiResponse::errors(null, 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function resolveBranchId(): ?int
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        if ($user->assignedToBranch()) {
            return (int) $user->branch_id;
        }

        return $user->effective_branch?->id ? (int) $user->effective_branch->id : null;
    }

    private function branchUnavailableResponse(): JsonResponse
    {
        return ApiResponse::errors(
            null,
            'No branch is available for voice configuration.',
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }
}
