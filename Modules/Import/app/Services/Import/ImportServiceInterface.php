<?php

namespace Modules\Import\Services\Import;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Modules\Import\Enums\ImportType;
use Modules\Import\Models\ImportBatch;

interface ImportServiceInterface
{
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    public function show(int $id): ImportBatch;

    public function create(ImportType $type, UploadedFile $file, array $options = []): ImportBatch;

    public function retry(int $id): ImportBatch;

    public function delete(int $id): void;

    public function getStructureFilters(): array;
}
