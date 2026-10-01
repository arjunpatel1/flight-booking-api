<?php

namespace Modules\Report\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

interface ReportInterface
{
    /**
     * Get report key
     *
     * @return string
     */
    public function key(): string;

    /**
     * Get report permission
     *
     * @return string
     */
    public function permission(): string;

    /**
     * Get report label
     *
     * @return string
     */
    public function label(): string;

    /**
     * Get report trans label
     *
     * @return string
     */
    public function transLabel(): string;

    /**
     * Get report attributes
     *
     * @return Collection
     */
    public function attributes(): Collection;

    /**
     * Get report trans attributes
     *
     * @return array
     */
    public function transAttributes(): array;

    /**
     * Get report filters
     *
     * @param Request $request
     * @return array
     */
    public function filters(Request $request): array;

    /**
     * Get report data
     *
     * @param Request $request
     * @param bool $withPagination
     * @return array|Collection
     */
    public function data(Request $request, bool $withPagination = true): array|Collection;

    /**
     * Get select columns
     *
     * @return array
     */
    public function columns(): array;

    /**
     * Get model
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get relationship
     *
     * @return array
     */
    public function with(): array;

    /**
     * Parse data
     *
     * @param Model $model
     * @return array
     */
    public function resource(Model $model): array;

    /**
     * Get a pipeline through
     *
     * @param Request $request
     * @return array
     */
    public function through(Request $request): array;

    /**
     * Render report
     *
     * @param Request $request
     * @return array
     */
    public function render(Request $request): array;

    /**
     * Determine has report search
     *
     * @return bool
     */
    public function hasSearch(): bool;
}
