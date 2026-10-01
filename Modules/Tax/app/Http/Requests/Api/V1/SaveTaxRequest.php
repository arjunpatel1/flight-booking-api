<?php

namespace Modules\Tax\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\OrderType;
use Modules\Tax\Enums\GstType;
use Modules\Tax\Enums\TaxType;

/**
 * @property int|null $branch_id
 * @property string|null $type
 */
class SaveTaxRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            ...$this->getTranslationRules(["name" => "required|string|max:255"]),
            ...$this->getBranchRule(true),
            "code" => [
                'bail',
                'required',
                'string',
                'max:50',
                Rule::unique('taxes')->where(function ($query) {
                    return $query->where('branch_id', $this->branch_id)
                        ->whereNull('deleted_at');
                })->ignore($this->route('id'))
            ],
            "type"     => ["required", Rule::enum(TaxType::class)],
            "gst_type" => ["nullable", Rule::enum(GstType::class)],
            "rate" => "required|numeric|min:0|max:100",
            "compound" => "required|boolean",
            "is_global" => "required|boolean",
            "is_active" => "required|boolean",
        ];

        if ($this->type === TaxType::Exclusive->value) {
            $rules = [
                ...$rules,
                "order_types" => "nullable|array",
                "order_types.*" => ["required", Rule::enum(OrderType::class)],
            ];
        }
        return $rules;
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "tax::attributes.taxes";
    }
}
