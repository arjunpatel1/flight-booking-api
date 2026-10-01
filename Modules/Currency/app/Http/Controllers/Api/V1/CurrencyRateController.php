<?php

namespace Modules\Currency\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Currency\Http\Requests\Api\V1\SaveCurrencyRateRequest;
use Modules\Currency\Services\CurrencyRate\CurrencyRateServiceInterface;
use Modules\Currency\Services\CurrencyRateExchanger;
use Modules\Currency\Transformers\Api\V1\CurrencyRateResource;
use Modules\Support\ApiResponse;

class CurrencyRateController extends Controller
{
    /**
     * Create a new instance of CurrencyRateController
     *
     * @param CurrencyRateServiceInterface $service
     */
    public function __construct(protected CurrencyRateServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of CurrencyRate models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: CurrencyRateResource::class
        );
    }

    /**
     * This method retrieves and returns a single CurrencyRate model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new CurrencyRateResource($this->service->show($id))
        );
    }

    /**
     * This method updates the provided data for the CurrencyRate model.
     *
     * @param SaveCurrencyRateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveCurrencyRateRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new CurrencyRateResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Refresh currency rates
     *
     * @param CurrencyRateExchanger $exchanger
     * @return JsonResponse
     */
    public function refresh(CurrencyRateExchanger $exchanger): JsonResponse
    {
        $this->service->refresh($exchanger);
        return ApiResponse::success(null, __("currency::messages.currency_rates_refreshed"));
    }

}
