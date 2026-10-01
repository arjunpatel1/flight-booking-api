<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Menu\Models\Menu;
use Modules\Branch\Models\Branch;

class UpdateOrderRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = auth()->user();

        $branchId = $user->assignedToBranch() ? $user->branch_id : Branch::main()->first()?->id;

        
        $menu = Menu::getActiveMenu($branchId);

        abort_if(is_null($menu), 400, __("pos::messages.menu_is_not_active"));

        return [
            // ✅ Order must exist
            "id" => [
                "required",
                "numeric",
                Rule::exists("orders", "id"),
            ],

            // // ✅ Only products are editable
            "products" => "required|array|min:1",

            "products.*.id" => [
                "required",
                "numeric",
                Rule::exists("products", "id")
                    ->whereNull("deleted_at")
                    ->where("is_active", 1)
                    ->where("menu_id", $menu->id),
            ],

            "products.*.quantity" => "required|numeric|min:1",
            "products.*.order_product_id" => "nullable|numeric|exists:order_products,id",

            // ✅ Options (no pricing / payment validation here)
            "products.*.options" => "nullable|array",
            "products.*.options.*.id" => "bail|required|numeric|exists:options,id,deleted_at,NULL,branch_id, $menu->branch_id",

            "products.*.options.*.values" => "required|array",

            "products.*.options.*.values.*.id" => [
                "required",
                "numeric",
                Rule::exists("option_values", "id")
                    ->where("branch_id", $menu->branch_id),
            ],

            "products.*.options.*.values.*.value" => "nullable|string",

            // // Optional editable fields
            "notes" => "nullable|string|max:1000",
            "guest_count" => "nullable|numeric|min:1",
        ];
    }

    protected function availableAttributes(): string
    {
        return "order::attributes.orders";
    }
}
