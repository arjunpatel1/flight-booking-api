<?php

namespace Modules\Support;

use Modules\Branch\Models\Branch;
use Modules\Support\Enums\GroupDateType;

readonly class GlobalStructureFilters
{
    /**
     * Get branch as filter
     *
     * @return array|null
     */
    public static function branch(): ?array
    {
        if (!auth()->user()?->assignedToBranch()) {
            return [
                "key" => 'branch_id',
                "label" => __('branch::branches.filters.branch'),
                "type" => 'select',
                "options" => Branch::list(),
            ];
        }

        return null;
    }

    /**
     * Get  group by date as filter
     *
     * @return array
     */
    public static function groupByDate(): array
    {
        return [
            "key" => 'group_by_date',
            "label" => __('admin::admin.filters.group_by_date'),
            "type" => 'select',
            "options" => GroupDateType::toArrayTrans(),
        ];
    }


    /**
     * Get is active status filter
     *
     * @return array
     */
    public static function active(): array
    {
        return [
            "key" => 'is_active',
            "label" => __('admin::admin.filters.activation'),
            "type" => 'select',
            "options" => [
                [
                    "id" => 1,
                    "name" => __('admin::admin.filters.active'),
                ],
                [
                    "id" => 0,
                    "name" => __('admin::admin.filters.inactive'),
                ]
            ],
        ];
    }

    /**
     * Get from created at filter
     *
     * @param string|null $label
     * @return array
     */
    public static function from(?string $label = null): array
    {
        return [
            "key" => 'from',
            "label" => $label ?: __('admin::admin.filters.from'),
            "type" => 'date',
        ];
    }

    /**
     * Get "to" created at filter
     *
     * @param string|null $label
     * @return array
     */
    public static function to(?string $label = null): array
    {
        return [
            "key" => 'to',
            "label" => $label ?: __('admin::admin.filters.to'),
            "type" => 'date',
        ];
    }
}
