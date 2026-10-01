<?php

namespace Modules\Order\Services\Course;

use Illuminate\Support\Collection;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Factories\PrintContents\PrintKitchenContentFactory;
use Modules\Printer\Jobs\DispatchPrintJob;

/**
 * Fires a held course to the kitchen: marks the course's items as fired and
 * dispatches their KOT only (reusing the same per-product print path as the
 * incremental "added items" KOT). Earlier-fired and un-coursed items are
 * untouched.
 */
class FireCourseService
{
    /**
     * Fire a held course. When $courseNumber is null, fires the next (lowest)
     * held course. Returns the fired course number, or null if there is nothing
     * held to fire.
     */
    public function fire(Order $order, ?int $courseNumber = null): ?int
    {
        $order->loadMissing('products');

        $held = $order->products->filter(fn (OrderProduct $p) => $p->isHeld());
        if ($held->isEmpty()) {
            return null;
        }

        $target = $courseNumber ?? (int) $held->min('course_number');

        $courseProducts = $held->filter(
            fn (OrderProduct $p) => (int) $p->course_number === (int) $target
        );
        if ($courseProducts->isEmpty()) {
            return null;
        }

        $courseProducts->each(
            fn (OrderProduct $p) => $p->forceFill(['fired_at' => now()])->save()
        );

        $this->dispatchCourseKot($order, $courseProducts->values(), (int) $target);

        return (int) $target;
    }

    private function dispatchCourseKot(Order $order, Collection $products, int $courseNumber): void
    {
        $factory = app(PrintKitchenContentFactory::class);
        $order->loadMissing(['waiter:id,name', 'table:id,name']);

        if (! $order->isScheduledForToday()) {
            return;
        }

        $collection = (new OrderProduct())->newCollection($products->all());
        $collection->load('product.categories');

        $payload = $factory->resourceForProducts($order, $collection, "COURSE {$courseNumber}");

        if (empty($payload['kitchens']) && collect($payload['products'] ?? [])->isEmpty()) {
            return;
        }

        DispatchPrintJob::dispatchAfterCommit(
            $order->id,
            PrintContentType::Kitchen,
            null,
            false,
            ['prepared_payload' => $payload]
        );
    }
}
