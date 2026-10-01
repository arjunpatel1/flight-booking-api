<?php

namespace Modules\Import\Services\Adapters;

use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Modules\Import\Contracts\ImportAdapter;
use Modules\Import\Services\Adapters\Concerns\BuildsTranslatedValues;
use Modules\Menu\Services\Menu\MenuServiceInterface;
use Modules\Order\Enums\OrderType;

class MenuImportAdapter implements ImportAdapter
{
    use BuildsTranslatedValues;

    public function __construct(private readonly MenuServiceInterface $menus)
    {
    }

    public function import(array $row, array $options = []): void
    {
        $data = [
            'name' => $this->translated($row, 'name'),
            'description' => $this->translated($row, 'description', false),
            'branch_id' => $row['branch_id'] ?? $options['branch_id'] ?? null,
            'is_active' => $this->boolean($row, 'is_active', true),
            'order_types' => collect(preg_split('/[|,]/', (string) ($row['order_types'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($value) => trim($value))->filter()->values()->all(),
        ];

        $branchRule = Rule::exists('branches', 'id')
            ->whereNull('deleted_at');

        if (!empty($options['tenant_id'])) {
            $branchRule->where('tenant_id', (int) $options['tenant_id']);
        }

        Validator::make($data, [
            'name' => ['required', 'array'],
            'description' => ['nullable', 'array'],
            'branch_id' => ['required', 'integer', $branchRule],
            'is_active' => ['required', 'boolean'],
            'order_types' => ['nullable', 'array'],
            'order_types.*' => ['string', Rule::in(OrderType::values())],
        ])->validate();

        $menu = $this->menus->store($data);

        // A row must not be counted as successful unless it is persisted with
        // the intended visibility. This prevents misleading "10 succeeded"
        // batches whose records cannot be found in the Menu module.
        if (!$menu->exists || (bool) $menu->is_active !== (bool) $data['is_active']) {
            throw new RuntimeException('The imported menu could not be verified after saving.');
        }
    }
}
