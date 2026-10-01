<?php

namespace Modules\Invoice\Services\Invoice;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Invoice\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface InvoiceServiceInterface
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
     * @return Invoice
     */
    public function getModel(): Invoice;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int|string $id
     * @return Invoice
     * @throws ModelNotFoundException
     */
    public function show(int|string $id): Invoice;

    /**
     * Get structure filters for frontend
     *
     * @return array
     */
    public function getStructureFilters(): array;

    public function summary(array $filters = []): array;

    public function export(array $filters = [], array $sorts = []): StreamedResponse;
}
