<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;
use Log;
use Modules\Core\Http\Requests\Request;
use Modules\Inventory\Models\PurchaseItem;

class MarkAsReceivedPurchaseRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "notes" => "nullable|string|max:1000",
            "reference" => "nullable|string|max:100",
            "items" => "required|array",
            "items.*.id" => "bail|required|numeric|exists:purchase_items,id,purchase_id,{$this->route('id')}",
            "items.*.received_quantity" => "required|numeric|min:0.01|max:99999.9999",
        ];
    }

    /**
     * Hook to add custom validation after base rules.
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $v) {
            $itemsInput = collect($this->input('items', []));
            $itemIds = $itemsInput->pluck('id')->filter()->unique()->values();

            $purchaseItems = PurchaseItem::query()->whereIn('id', $itemIds)->get()->keyBy('id');

            foreach ($itemsInput as $item) {
                $id = $item['id'] ?? null;
                $requestedQty = $item['received_quantity'] ?? 0;

                if (!$id || !isset($purchaseItems[$id])) {
                    continue;
                }

                $purchaseItem = $purchaseItems[$id];
                $remaining = (float)($purchaseItem->quantity - $purchaseItem->received_quantity);

                if (bccomp((string)$requestedQty, (string)$remaining, 4) === 1) {
                    $v->errors()->add(
                        "items.{$purchaseItem->id}.received_quantity",
                        $remaining == 0
                            ? __("inventory::messages.no_remaining_quantity")
                            : __("inventory::messages.received_quantity_exceeds", [
                            'max' => number_format($remaining, 4),
                        ])
                    );
                }
            }
        });
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "inventory::attributes.purchases";
    }
}
