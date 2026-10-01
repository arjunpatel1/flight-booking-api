<?php

namespace Modules\Category\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Support\ApiResponse;

readonly class CategoryTreeResponse implements Responsable
{

    /**
     * Create a new instance.
     *
     * @param \Illuminate\Database\Eloquent\Collection $categories
     */
    public function __construct(private \Illuminate\Database\Eloquent\Collection $categories)
    {
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function toResponse($request): JsonResponse
    {
        return ApiResponse::success($this->transform());
    }

    /**
     * Transform the categories.
     *
     * @return Collection
     */
    public function transform(): Collection
    {
        return $this->categories->map(
            fn($category) => [
                'id' => $category->id,
                'parent' => $category->parent_id ?: '#',
                'name' => $category->name,
            ]
        );
    }
}
