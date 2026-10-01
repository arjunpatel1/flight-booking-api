<?php

namespace Modules\Menu\Services\Menu;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Menu\Models\Menu;
use Modules\Option\Models\Option;
use Modules\Order\Enums\OrderType;
use Modules\Product\Models\Product;
use Modules\Tax\Models\Tax;
use Modules\Support\GlobalStructureFilters;

class MenuService implements MenuServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("menu::menus.menu");
    }

    /** @inheritDoc */
    public function show(int $id): Menu
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Menu
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getModel(): Menu
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Menu::class;
    }

    /** @inheritDoc */
    public function store(array $data): Menu
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Menu
    {
        $menu = $this->findOrFail($id);
        $menu->update($data);

        return $menu;
    }

    public function copyToBranch(int $id, int $branchId, ?string $name = null, bool $activate = false): Menu
    {
        return DB::transaction(function () use ($id, $branchId, $name, $activate): Menu {
            $source = Menu::query()->withoutGlobalActive()->findOrFail($id);
            $targetBranch = Branch::query()->withoutGlobalActive()->findOrFail($branchId);

            abort_if((int) $source->branch_id === (int) $targetBranch->id, 422, 'Choose a different target branch.');

            $copy = $source->replicate(['id', 'uuid', 'created_at', 'updated_at', 'deleted_at']);
            $copy->branch_id = $targetBranch->id;
            $copy->is_active = $activate;
            if (filled($name)) {
                $translations = $source->getTranslations('name');
                $translations[app()->getLocale()] = trim((string) $name);
                $copy->setTranslations('name', $translations);
            }
            $copy->save();

            if ($activate) {
                Menu::query()->withoutGlobalActive()
                    ->where('branch_id', $targetBranch->id)
                    ->whereKeyNot($copy->id)
                    ->update(['is_active' => false]);
            }

            $categories = Category::query()->withoutGlobalActive()
                ->where('menu_id', $source->id)->defaultOrder()->get();
            $categoryMap = [];
            foreach ($categories as $category) {
                $clone = $category->replicate(['id', 'created_at', 'updated_at', 'deleted_at', '_lft', '_rgt', 'parent_id']);
                $clone->menu_id = $copy->id;
                $clone->parent_id = $category->parent_id ? ($categoryMap[$category->parent_id] ?? null) : null;
                $clone->save();
                $categoryMap[$category->id] = $clone->id;
            }
            Category::query()->where('menu_id', $copy->id)->fixTree();

            $products = Product::query()->withoutGlobalActive()
                ->without(['branch'])
                ->with(['categories:id', 'options.values', 'taxes'])
                ->where('menu_id', $source->id)
                ->get();
            $optionMap = [];
            $taxMap = [];

            foreach ($products as $product) {
                $clone = $product->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                $clone->menu_id = $copy->id;
                $clone->save();

                $clone->categories()->sync($product->categories->pluck('id')->map(
                    fn ($categoryId) => $categoryMap[$categoryId] ?? null
                )->filter()->values()->all());

                foreach ($product->options as $option) {
                    if (! isset($optionMap[$option->id])) {
                        $optionClone = $option->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                        $optionClone->branch_id = $targetBranch->id;
                        $optionClone->is_global = false;
                        $optionClone->save();
                        foreach ($option->values as $value) {
                            $valueClone = $value->replicate(['id', 'created_at', 'updated_at']);
                            $valueClone->option_id = $optionClone->id;
                            $valueClone->branch_id = $targetBranch->id;
                            $valueClone->save();
                        }
                        $optionMap[$option->id] = $optionClone->id;
                    }
                    $clone->options()->syncWithoutDetaching([$optionMap[$option->id]]);
                }

                foreach ($product->taxes as $tax) {
                    if (! isset($taxMap[$tax->id])) {
                        $targetTax = Tax::query()->withoutGlobalActive()
                            ->withOutGlobalBranchPermission()
                            ->where('branch_id', $targetBranch->id)
                            ->where('code', $tax->code)
                            ->first();
                        if (! $targetTax) {
                            $targetTax = $tax->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                            $targetTax->branch_id = $targetBranch->id;
                            $targetTax->is_global = false;
                            $targetTax->save();
                        }
                        $taxMap[$tax->id] = $targetTax->id;
                    }
                    $clone->taxes()->syncWithoutDetaching([$taxMap[$tax->id]]);
                }
            }

            return $copy->load('branch:id,name');
        });
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "branches" => Branch::list(),
            "order_types" => OrderType::toArrayTrans(),
        ];
    }

    /** @inheritDoc */
    public function getCurrentMenu(?int $menuId = null, bool $withBranch = false): ?Menu
    {
        $user = auth()->user();

        if (!is_null($menuId)) {
            return Menu::query()
                ->withoutGlobalActive()
                ->when($withBranch, fn(Builder $query) => $query->with(["branch"]))
                ->where('id', $menuId)
                ->first();
        }

        if ($user->assignedToBranch()) {
            return Menu::getActiveMenu($user->branch_id, $withBranch);
        } else {
            $mainBranch = Branch::query()->withoutGlobalActive()->main()->first();
            if (!is_null($mainBranch)) {
                return Menu::getActiveMenu($mainBranch->id, $withBranch);
            }
        }

        return null;
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name"])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }
}
