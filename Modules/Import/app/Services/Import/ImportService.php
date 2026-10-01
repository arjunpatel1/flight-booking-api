<?php

namespace Modules\Import\Services\Import;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Enums\ImportType;
use Modules\Import\Jobs\ProcessImportBatchJob;
use Modules\Import\Models\ImportBatch;
use Modules\Support\GlobalStructureFilters;

class ImportService implements ImportServiceInterface
{
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        return ImportBatch::query()
            ->visibleTo(auth()->user())
            ->filters($filters)
            ->sortBy($sorts)
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int $id): ImportBatch
    {
        return ImportBatch::query()->visibleTo(auth()->user())->findOrFail($id);
    }

    public function create(ImportType $type, UploadedFile $file, array $options = []): ImportBatch
    {
        $batch = ImportBatch::query()->create([
            'type' => $type,
            'status' => ImportStatus::Pending,
            'original_filename' => $file->getClientOriginalName(),
            'source_file_path' => $file->store('imports'),
            'options' => $options,
        ]);

        // Production workers always consume the default queue. Keeping imports
        // on an undeclared dedicated queue left batches permanently pending
        // when that optional worker was not configured.
        ProcessImportBatchJob::dispatch($batch)->onQueue(
            (string) config('import.queue', 'default')
        );

        return $batch;
    }

    public function retry(int $id): ImportBatch
    {
        $batch = ImportBatch::query()->visibleTo(auth()->user())->findOrFail($id);

        abort_unless(
            in_array($batch->status, [ImportStatus::Pending, ImportStatus::Failed], true),
            422,
            __('import::imports.retry_not_allowed')
        );

        $batch->update([
            'status' => ImportStatus::Pending,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
            'started_at' => null,
            'finished_at' => null,
        ]);

        ProcessImportBatchJob::dispatch($batch->fresh())->onQueue(
            (string) config('import.queue', 'default')
        );

        return $batch->fresh();
    }

    public function delete(int $id): void
    {
        $batch = ImportBatch::query()->visibleTo(auth()->user())->findOrFail($id);

        abort_unless(
            in_array($batch->status, [
                ImportStatus::Pending,
                ImportStatus::Completed,
                ImportStatus::CompletedWithErrors,
                ImportStatus::Failed,
            ], true),
            422,
            __('import::imports.delete_not_allowed')
        );

        if ($batch->source_file_path) {
            Storage::delete($batch->source_file_path);
        }

        $batch->delete();
    }

    public function getStructureFilters(): array
    {
        return [
            [
                'key' => 'type',
                'label' => __('import::imports.columns.type'),
                'type' => 'select',
                'options' => collect(ImportType::cases())->map(fn(ImportType $type) => [
                    'id' => $type->value,
                    'name' => __("import::imports.types.{$type->value}"),
                ])->all(),
            ],
            [
                'key' => 'status',
                'label' => __('import::imports.columns.status'),
                'type' => 'select',
                'options' => collect(ImportStatus::cases())->map(fn(ImportStatus $status) => [
                    'id' => $status->value,
                    'name' => __("import::imports.statuses.{$status->value}"),
                ])->all(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }
}
