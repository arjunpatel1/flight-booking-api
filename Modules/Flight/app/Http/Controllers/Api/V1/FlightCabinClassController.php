<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightCabinClass;
use Modules\Support\ApiResponse;

class FlightCabinClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FlightCabinClass::query()->orderBy('sort_order')->orderBy('name');
        if (! $request->boolean('include_inactive')) $query->where('is_active', true);
        return ApiResponse::success($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', 'unique:flight_cabin_classes,code'],
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['sort_order'] ??= (int) FlightCabinClass::query()->max('sort_order') + 10;
        $data['is_active'] = true;
        return ApiResponse::created(FlightCabinClass::create($data), message: 'Cabin class created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $class = FlightCabinClass::findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:30', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', Rule::unique('flight_cabin_classes', 'code')->ignore($class->id)],
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['code'])) $data['code'] = strtoupper($data['code']);
        $class->update($data);
        return ApiResponse::updated($class->refresh(), message: 'Cabin class updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $class = FlightCabinClass::findOrFail($id);
        $class->update(['is_active' => false]);
        return ApiResponse::success($class, 'Cabin class disabled successfully');
    }
}
