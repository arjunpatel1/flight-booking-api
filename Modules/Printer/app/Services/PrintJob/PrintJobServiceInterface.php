<?php

namespace Modules\Printer\Services\PrintJob;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Printer\Models\PrintJob;

interface PrintJobServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function retry(string $id): PrintJob;

    public function getStructureFilters(): array;

    public function summary(array $filters = []): array;

    public function diagnostics(array $filters = []): array;
}
