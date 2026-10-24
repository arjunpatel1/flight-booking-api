<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Booking\FlightOfferService;
use Modules\Support\ApiResponse;

class FlightOfferController extends Controller
{
    public function __construct(protected FlightOfferService $offers) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['quote_id' => 'required|string|max:100']);
        try {
            return ApiResponse::success($this->offers->offersForQuote($data['quote_id']));
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => 'required|string|max:100',
            'code' => 'required|string|max:200',
            'discount_id' => 'nullable|integer|min:1',
        ]);
        try {
            return ApiResponse::success($this->offers->validateCode($data['quote_id'], $data['code'], $data['discount_id'] ?? null));
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }
}
