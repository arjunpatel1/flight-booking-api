<?php

namespace Modules\Inventory\Database\Seeders;

use DB;
use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\PurchaseItem;
use Modules\Inventory\Models\PurchaseReceipt;
use Modules\Inventory\Models\PurchaseReceiptItem;
use Modules\User\Models\User;
use Throwable;

class PurchaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @throws Throwable
     */
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $userId = User::query()
                ->where("branch_id", $branch->id)
                ->inRandomOrder()
                ->value('id');

            if (is_null($userId)) {
                continue;
            }

            $purchases = collect()
                ->merge(Purchase::factory()->count(2)->pending()->forBranch($branch)->create())
                ->merge(Purchase::factory()->count(2)->partiallyReceived()->forBranch($branch)->create())
                ->merge(Purchase::factory()->count(2)->received()->forBranch($branch)->create());

            foreach ($purchases as $purchase) {
                DB::transaction(function () use ($purchase, $userId) {
                    $items = PurchaseItem::factory()
                        ->count(rand(3, 7))
                        ->forPurchase($purchase)
                        ->create();

                    $sub = $items->sum(fn($item) => $item->line_total->amount());
                    $purchase->discount = fake()->boolean(30) ? fake()->randomFloat(3, 0, $sub * 0.10) : 0;
                    $purchase->tax = fake()->boolean() ? fake()->randomFloat(3, 0, $sub * 0.16) : 0;
                    $purchase->sub_total = $sub;
                    $purchase->total = $sub - $purchase->discount->amount() + $purchase->tax->amount();
                    $purchase->save();

                    if (!in_array($purchase->status, [
                        PurchaseStatus::PartiallyReceived,
                        PurchaseStatus::Received,
                    ], true)) {
                        return;
                    }

                    $plan = [];
                    foreach ($items as $item) {
                        $ordered = (float)$item->quantity;

                        if ($purchase->status === PurchaseStatus::Received->value) {
                            $planned = $ordered;
                        } else {
                            $min = max(0.40 * $ordered, 0.1);
                            $max = max(0.85 * $ordered, $min);
                            $planned = (float)number_format(fake()->randomFloat(3, $min, $max), 3, '.', '');
                        }

                        $plan[$item->id] = [
                            'ordered' => $ordered,
                            'planned_total' => min($ordered, $planned),
                            'received_so_far' => 0.0,
                        ];
                    }

                    $receiptsCount = $purchase->status === PurchaseStatus::Received->value
                        ? rand(1, 3)
                        : rand(2, 3);

                    for ($r = 1; $r <= $receiptsCount; $r++) {
                        $receipt = PurchaseReceipt::query()
                            ->create([
                                'purchase_id' => $purchase->id,
                                'received_by' => $userId,
                                'reference' => fake()->boolean(60) ? fake()->bothify('GRN-#####') : null,
                                'notes' => fake()->boolean(20) ? fake()->sentence() : null,
                                'received_at' => now()->subDays(rand(0, 3))->addMinutes(rand(0, 1440)),
                            ]);

                        foreach ($items as $item) {
                            $p = $plan[$item->id];
                            $remaining = $p['planned_total'] - $p['received_so_far'];
                            if ($remaining <= 0) {
                                continue;
                            }

                            $receiptsLeft = $receiptsCount - $r + 1;

                            if ($receiptsLeft > 1) {
                                $minSlice = max($remaining * 0.20, 0.01);
                                $maxSlice = max($remaining * 0.60, $minSlice);
                                $slice = (float)number_format(fake()->randomFloat(3, $minSlice, $maxSlice), 3, '.', '');
                            } else {
                                $slice = (float)number_format($remaining, 3, '.', '');
                            }

                            $slice = min($slice, $p['ordered'] - $p['received_so_far']);
                            if ($slice <= 0) continue;

                            PurchaseReceiptItem::query()
                                ->create([
                                    'purchase_receipt_id' => $receipt->id,
                                    'purchase_item_id' => $item->id,
                                    'received_quantity' => $slice,
                                ]);

                            $plan[$item->id]['received_so_far'] += $slice;
                        }
                    }

                    foreach ($items as $item) {
                        $sumReceived = PurchaseReceiptItem::query()
                            ->whereHas('purchaseReceipt', function ($q) use ($purchase) {
                                $q->where('purchase_id', $purchase->id);
                            })
                            ->where('purchase_item_id', $item->id)
                            ->sum('received_quantity');

                        $item->received_quantity = (float)number_format($sumReceived, 3, '.', '');
                        $item->save();
                    }

                    $totalOrdered = (float)$items->sum('quantity');
                    $totalReceived = (float)$items->sum('received_quantity');

                    if ($totalReceived <= 0.0001) {
                        $purchase->status = PurchaseStatus::Pending->value;
                    } elseif (abs($totalReceived - $totalOrdered) < 0.0001) {
                        $purchase->status = PurchaseStatus::Received->value;
                    } else {
                        $purchase->status = PurchaseStatus::PartiallyReceived->value;
                    }
                    $purchase->save();
                });
            }
        }
    }
}
