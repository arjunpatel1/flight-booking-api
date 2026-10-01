<?php

namespace Modules\Order\Services\Order;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Throwable;

interface OrderServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return Order
     */
    public function getModel(): Order;


    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Aggregate order KPIs for the orders list, honouring the same filters:
     * total orders, total sales, average order value, and counts by status and
     * payment status.
     *
     * @param array $filters
     * @return array
     */
    public function stats(array $filters = []): array;

    /**
     * Show the specified resource.
     *
     * @param int|string $id
     * @param array|null $with
     * @return Order
     * @throws ModelNotFoundException
     */
    public function show(int|string $id, ?array $with = null): Order;

    /**
     * Get structure filters for frontend
     *
     * @return array
     */
    public function getStructureFilters(): array;

    /**
     * Cancel order
     *
     * @param int|string $id
     * @param array $data
     * @return void
     * @throws Throwable
     */
    public function cancel(int|string $id, array $data): void;

    /**
     * Refund order
     *
     * @param int|string $id
     * @param array $data
     * @return void
     * @throws Throwable
     */
    public function refund(int|string $id, array $data): void;

    /**
     * Init edit order
     *
     * @param int|string $id
     * @return array
     * @throws Throwable
     */
    public function initEdit(int|string $id): array;

    /**
     * Get specific resource
     *
     * @param int|string $id
     * @param bool $withBranch
     * @return Order|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id, bool $withBranch = false): Order|Builder|EloquentCollection|array;

    /**
     * Get update status meta
     *
     * @param int|string $id
     * @return array
     */
    public function getUpdateStatusMeta(int|string $id): array;

    /**
     * Get active orders
     *
     * @param int|null $branchId
     * @return LengthAwarePaginator
     */
    public function activeOrders(?int $branchId = null, ?int $waiterId = null): LengthAwarePaginator;

    /**
     * Get upcoming orders
     *
     * @param int|null $branchId
     * @return LengthAwarePaginator
     */
    public function upcomingOrders(?int $branchId = null, ?int $waiterId = null): LengthAwarePaginator;

    /**
     * Move to next status
     *
     * @param string|int $id
     * @return OrderStatus
     * @throws Throwable
     */
    public function moveToNextStatus(string|int $id): OrderStatus;

    /**
     * Kitchen move to next status
     *
     * @param string|int $id
     * @return OrderStatus
     * @throws Throwable
     */
    public function kitchenMoveToNextStatus(string|int $id): OrderStatus;

    /**
     * Preview print
     *
     * @param string|int $id
     * @param PrintContentType $type
     * @param int|null $kitchenId
     * @return string
     * @throws Throwable
     */
    public function previewPrint(string|int $id, PrintContentType $type, ?int $kitchenId = null): string;

    /**
     * Get print meta
     *
     * @param string|int $id
     * @param int|null $branchId
     * @param int|null $registerId
     * @return array
     * @throws Throwable
     */
    public function printMeta(string|int $id, ?int $branchId = null, ?int $registerId = null): array;

    /**
     * Print
     *
     * @param string|int $id
     * @param PrintContentType $type
     * @param int|null $specificId
     * @return void
     * @throws Throwable
     */
    public function print(string|int $id, PrintContentType $type, ?int $specificId = null): void;
}
