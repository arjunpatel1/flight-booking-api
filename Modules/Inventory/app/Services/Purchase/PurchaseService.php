<?php

namespace Modules\Inventory\Services\Purchase;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Currency\Models\CurrencyRate;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\PurchaseReceipt;
use Modules\Inventory\Models\PurchaseReceiptItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Supplier;
use Modules\Support\GlobalStructureFilters;
use Throwable;

class PurchaseService implements PurchaseServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("inventory::purchases.purchase");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch", "supplier"])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Purchase
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Purchase::class;
    }

    /** @inheritDoc */
    public function show(int $id, bool $withReceipt = false): Purchase
    {
        $with = ["branch", "supplier", "items"];

        if ($withReceipt) {
            $with = [...$with, ...["purchaseReceipts" => fn($query) => $query->with('items', "receivedBy")]];
        }

        return $this->getModel()
            ->query()
            ->with($with)
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Purchase
    {
        return $this->getModel()->query()->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Purchase
    {
        /** @var Branch $branch */
        $branch = Branch::find($data['branch_id']);

        $purchase = $this->getModel()->query()->create([
            ... Arr::except($data, ['items']),
            "currency" => $branch->currency,
            "currency_rate" => CurrencyRate::for($branch->currency),
            "status" => $data['status'] ?? PurchaseStatus::Pending,
        ]);
        $purchase->syncItems($data['items']);
        $purchase->calculateAndSyncTotals();

        return $purchase;
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Purchase
    {
        $purchase = $this->findOrFail($id);

        abort_if(
            in_array($purchase->status, [PurchaseStatus::Received, PurchaseStatus::Cancelled]),
            400, __("inventory::messages.purchase_cannot_be_updated")
        );

        $items = $data['items'];
        $data = Arr::except($data, ['items']);

        $purchase->update($data);
        $purchase->syncItems($items);
        $purchase->calculateAndSyncTotals();

        return $purchase;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->whereIn('status', [PurchaseStatus::Draft, PurchaseStatus::Pending])
            ->whereIn("id", parseIds($ids))->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $branchId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'supplier_id',
                "label" => __('inventory::purchases.filters.supplier'),
                "type" => 'select',
                "options" => !is_null($branchId) ? Supplier::list($branchId) : [],
                "depends" => "branch_id"
            ],
            [
                "key" => 'status',
                "label" => __('inventory::purchases.filters.status'),
                "type" => 'select',
                "options" => PurchaseStatus::toArrayTrans(),
            ],
            [
                "key" => 'expectedAt',
                "label" => __('inventory::purchases.filters.expected_at'),
                "type" => 'date',
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        if (is_null($branchId)) {
            return [
                "branches" => Branch::list(),
            ];
        } else {
            return [
                "ingredients" => Ingredient::list($branchId),
                "suppliers" => Supplier::list($branchId),
            ];
        }
    }

    /** @inheritDoc
     *
     */
    public function markAsReceived(int $id, array $data): void
    {
        $purchase = $this->getModel()->query()->with("items")->findOrFail($id);

        abort_if($purchase->status == PurchaseStatus::Received, 400, __("inventory::messages.purchase_already_received"));

        DB::beginTransaction();

        try {
            /** @var PurchaseReceipt $receipt */
            $receipt = $purchase->purchaseReceipts()
                ->create([
                    'received_by' => auth()->id(),
                    'notes' => $data['notes'],
                    'reference' => $data['reference'],
                    'received_at' => now(),
                ]);

            $receiptItems = [];

            foreach ($data['items'] as $item) {
                if ($item['received_quantity'] <= 0) continue;

                $receiptItems[] = new PurchaseReceiptItem([
                    'purchase_item_id' => $item['id'],
                    'received_quantity' => $item['received_quantity'],
                ]);

                $purchaseItem = $purchase->items->firstWhere('id', $item['id']);
                if (!$purchaseItem || !$purchaseItem->ingredient_id) {
                    continue;
                }

                StockMovement::query()
                    ->create([
                        'ingredient_id' => $purchaseItem->ingredient_id,
                        'branch_id' => $purchase->branch_id,
                        'type' => StockMovementType::In,
                        'quantity' => $item['received_quantity'],
                        'note' => $data['note'] ?? null,
                        "source_id" => $purchase->id,
                        "source_type" => $purchase->getMorphClass()
                    ]);
            }

            $receipt->items()->saveMany($receiptItems);

            $purchase->recalculateReceivedQuantities();

            DB::commit();
        } catch (Throwable) {
            DB::rollBack();
            abort(400, __("inventory::messages.purchase_received_failed"));
        }
    }
}
