<?php

namespace Modules\WhatsAppCenter\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Models\WhatsAppTemplate;

class WhatsAppTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = WhatsAppTemplate::query()
            ->when($request->get('category'), fn($q, $c) => $q->where('category', $c))
            ->when($request->get('is_active') !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate(NexDine::paginate());

        return ApiResponse::pagination($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100', 'unique:whatsapp_templates,name'],
            'label'         => ['required', 'string', 'max:200'],
            'category'      => ['required', 'string', 'max:50'],
            'language_code' => ['required', 'string', 'max:10'],
            'body'          => ['required', 'string'],
            'variables'     => ['sometimes', 'nullable', 'array'],
            'event'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(WhatsAppTemplate::create($data), 201);
    }

    public function update(Request $request, WhatsAppTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:100', 'unique:whatsapp_templates,name,' . $template->id],
            'label'     => ['sometimes', 'string', 'max:200'],
            'body'      => ['sometimes', 'string'],
            'variables' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template->update($data);

        return ApiResponse::success($template->fresh());
    }

    public function destroy(WhatsAppTemplate $template): JsonResponse
    {
        abort_if($template->is_system, 403, 'Cannot delete system templates.');

        $template->delete();

        return ApiResponse::success(['deleted' => true]);
    }
}
