<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class SaveFloorPlannerSnapshotRequest extends Request
{
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1', 'max:20'],
            // Optimistic-lock token: epoch-ms of the floor's updated_at when it was loaded.
            'baseVersion' => ['nullable', 'integer'],
            'canvas' => ['required', 'array'],
            'canvas.width' => ['required', 'integer', 'min:600', 'max:10000'],
            'canvas.height' => ['required', 'integer', 'min:400', 'max:10000'],
            'canvas.background' => ['nullable', 'string', 'max:80'],
            'canvas.minScale' => ['nullable', 'numeric', 'min:0.05', 'max:1'],
            'canvas.maxScale' => ['nullable', 'numeric', 'min:1', 'max:10'],

            'grid' => ['required', 'array'],
            'grid.size' => ['required', 'numeric', 'min:4', 'max:200'],
            'grid.visible' => ['required', 'boolean'],
            'grid.snap' => ['required', 'boolean'],
            'grid.subdivisions' => ['required', 'integer', 'min:1', 'max:12'],
            'grid.color' => ['nullable', 'string', 'max:80'],

            'zones' => ['nullable', 'array', 'max:500'],
            'zones.*.id' => ['required', 'string', 'max:80'],
            'zones.*.backendId' => ['nullable', 'integer'],
            'zones.*.name' => ['required', 'string', 'max:120'],
            'zones.*.color' => ['nullable', 'string', 'max:80'],
            'zones.*.capacity' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'zones.*.rect' => ['required', 'array'],
            'zones.*.rect.x' => ['required', 'numeric', 'min:-20000', 'max:20000'],
            'zones.*.rect.y' => ['required', 'numeric', 'min:-20000', 'max:20000'],
            'zones.*.rect.width' => ['required', 'numeric', 'min:40', 'max:10000'],
            'zones.*.rect.height' => ['required', 'numeric', 'min:40', 'max:10000'],
            'zones.*.locked' => ['required', 'boolean'],
            'zones.*.hidden' => ['required', 'boolean'],
            'zones.*.zIndex' => ['required', 'integer', 'min:-100000', 'max:100000'],

            'objects' => ['nullable', 'array', 'max:1500'],
            'objects.*.id' => ['required', 'string', 'max:80'],
            'objects.*.type' => ['required', 'string', Rule::in(['table', 'chair', 'wall', 'door', 'window', 'plant', 'counter', 'kitchen', 'decoration'])],
            'objects.*.backendId' => ['nullable', 'integer'],
            'objects.*.x' => ['required', 'numeric', 'min:-20000', 'max:20000'],
            'objects.*.y' => ['required', 'numeric', 'min:-20000', 'max:20000'],
            'objects.*.width' => ['required', 'numeric', 'min:4', 'max:10000'],
            'objects.*.height' => ['required', 'numeric', 'min:4', 'max:10000'],
            'objects.*.rotation' => ['required', 'numeric', 'min:0', 'max:359.99'],
            'objects.*.scale' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'objects.*.zIndex' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'objects.*.locked' => ['required', 'boolean'],
            'objects.*.hidden' => ['required', 'boolean'],
            'objects.*.zoneId' => ['nullable', 'string', 'max:80'],
            'objects.*.groupId' => ['nullable', 'string', 'max:80'],
            'objects.*.metadata' => ['nullable', 'array', 'max:40'],

            'objects.*.shape' => ['required_if:objects.*.type,table', 'nullable', Rule::in(['round', 'square', 'rectangle', 'oval', 'booth', 'bar', 'long', 'vip', 'circle'])],
            'objects.*.name' => ['required_if:objects.*.type,table', 'nullable', 'string', 'max:120'],
            'objects.*.capacity' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'objects.*.seatCount' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'objects.*.status' => ['nullable', Rule::in(['available', 'occupied', 'reserved', 'cleaning', 'blocked', 'bill_pending', 'merged', 'maintenance'])],
            'objects.*.reservationAllowed' => ['nullable', 'boolean'],
            'objects.*.qrCode' => ['nullable', 'string', 'max:255'],
            'objects.*.colorTheme' => ['nullable', 'string', 'max:80'],
            'objects.*.border' => ['nullable', 'array'],
            'objects.*.shadow' => ['nullable', 'array'],
            'objects.*.material' => ['nullable', 'string', 'max:80'],
            'objects.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function availableAttributes(): string
    {
        return 'seatingplan::attributes.floors';
    }
}
