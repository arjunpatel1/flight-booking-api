<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Services\Course\FireCourseService;
use Modules\Printer\Factories\PrintContents\PrintKitchenContentFactory;
use Modules\Product\Models\Product;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class CourseFiringTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Artisan::call('permission:sync-default-roles');
    }

    private function orderProduct(Order $order, int $course, ?string $firedAt = null): OrderProduct
    {
        $menu = $this->makeMenu($order->branch);
        $product = Product::factory()->create([
            'name' => ['en' => "P{$course}"],
            'menu_id' => $menu->id,
        ]);

        return $order->products()->create([
            'product_id' => $product->id,
            'currency' => 'INR',
            'currency_rate' => 1,
            'quantity' => 1,
            'course_number' => $course,
            'fired_at' => $firedAt,
            'unit_price' => 100,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
        ]);
    }

    public function test_fire_next_course_fires_lowest_held_course_and_dispatches_its_kot_only(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch, ['order_date' => today()]);

        $this->orderProduct($order, 1, now()->toDateTimeString()); // already fired
        $course2 = $this->orderProduct($order, 2);                  // held
        $course3 = $this->orderProduct($order, 3);                  // held

        $fired = app(FireCourseService::class)->fire($order->fresh());

        $this->assertSame(2, $fired);
        $this->assertNotNull($course2->fresh()->fired_at, 'Course 2 should be fired');
        $this->assertNull($course3->fresh()->fired_at, 'Course 3 should stay held');
    }

    public function test_fire_specific_course_and_then_nothing_left_to_fire(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch, ['order_date' => today()]);

        $this->orderProduct($order, 1, now()->toDateTimeString());
        $this->orderProduct($order, 2);

        $this->assertSame(2, app(FireCourseService::class)->fire($order->fresh(), 2));
        // All courses fired now → nothing held.
        $this->assertNull(app(FireCourseService::class)->fire($order->fresh()));
    }

    public function test_kitchen_ticket_excludes_held_courses(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch, ['order_date' => today()]);

        $fired = $this->orderProduct($order, 1, now()->toDateTimeString());
        $this->orderProduct($order, 2); // held

        $payload = app(PrintKitchenContentFactory::class)->resource($order->fresh()->load('products.product'));

        $names = collect($payload['products'])->pluck('id');
        $this->assertTrue($names->contains($fired->id), 'Fired course must be on the ticket');
        $this->assertCount(1, $payload['products'], 'Held course must be excluded from the ticket');
    }
}
