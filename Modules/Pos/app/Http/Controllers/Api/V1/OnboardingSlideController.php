<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Models\OnboardingSlide;
use Modules\Pos\Transformers\Api\V1\OnboardingSlideResource;
use Modules\Support\ApiResponse;

class OnboardingSlideController extends Controller
{
    /**
     * Public endpoint — Flutter app fetches active slides (no auth).
     */
    public function publicIndex(): JsonResponse
    {
        $slides = OnboardingSlide::with('image')
            ->active()
            ->ordered()
            ->get();

        return ApiResponse::success(
            body: OnboardingSlideResource::collection($slides)
        );
    }

    /**
     * Admin list — all slides regardless of active state.
     */
    public function index(): JsonResponse
    {
        $slides = OnboardingSlide::with('image')
            ->ordered()
            ->get();

        return ApiResponse::success(
            body: OnboardingSlideResource::collection($slides)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:120'],
            'description'  => ['required', 'string', 'max:500'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'cta_label'    => ['sometimes', 'nullable', 'string', 'max:50'],
            'image_id'     => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'sort_order'   => ['sometimes', 'integer', 'min:0'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $slide = OnboardingSlide::create($data);
        $slide->load('image');

        return ApiResponse::success(
            body: new OnboardingSlideResource($slide),
            status: 201
        );
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $slide = OnboardingSlide::findOrFail($id);

        $data = $request->validate([
            'title'        => ['sometimes', 'string', 'max:120'],
            'description'  => ['sometimes', 'string', 'max:500'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'cta_label'    => ['sometimes', 'nullable', 'string', 'max:50'],
            'image_id'     => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'sort_order'   => ['sometimes', 'integer', 'min:0'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $slide->update($data);
        $slide->load('image');

        return ApiResponse::success(
            body: new OnboardingSlideResource($slide)
        );
    }

    public function destroy(string $ids): JsonResponse
    {
        $idList = array_filter(explode(',', $ids), 'is_numeric');
        OnboardingSlide::whereIn('id', $idList)->delete();

        return ApiResponse::success();
    }

    public function toggleActive(int $id): JsonResponse
    {
        $slide = OnboardingSlide::findOrFail($id);
        $slide->update(['is_active' => !$slide->is_active]);
        $slide->load('image');

        return ApiResponse::success(
            body: new OnboardingSlideResource($slide)
        );
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slides'              => ['required', 'array'],
            'slides.*.id'         => ['required', 'integer', 'exists:onboarding_slides,id'],
            'slides.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($data['slides'] as $item) {
            OnboardingSlide::where('id', $item['id'])
                ->update(['sort_order' => $item['sort_order']]);
        }

        return ApiResponse::success();
    }
}
