<?php

namespace Modules\Expense\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Expense\Models\Expense;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "amount" => $this->amount->toArray(),
            "expense_date" => dateTimeFormat($this->expense_date),
            "expense_date_iso" => $this->expense_date?->toDateString(),
            "category" => [
                "id" => $this->expense_category_id,
                "name" => $this->relationLoaded("category") ? $this->category?->name : null,
            ],
            "user" => [
                "id" => $this->user_id,
                "name" => $this->relationLoaded("user") ? $this->user?->name : null,
            ],
            "description" => $this->description,
            "reference" => $this->reference,
            "status" => $this->status,
            "notes" => $this->notes,
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
