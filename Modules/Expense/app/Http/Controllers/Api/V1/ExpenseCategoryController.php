<?php

namespace Modules\Expense\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Expense\Services\ExpenseCategory\ExpenseCategoryServiceInterface;
use Modules\Support\ApiResponse;

class ExpenseCategoryController extends Controller
{
    public function __construct(protected ExpenseCategoryServiceInterface $service)
    {
    }

    /**
     * Lightweight active category list for the expense form dropdown.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(body: $this->service->list());
    }
}
