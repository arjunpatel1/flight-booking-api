<?php

namespace Modules\Import\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Import\Contracts\ImportAdapter;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Models\ImportBatch;
use Throwable;

class ProcessImportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly ImportBatch $batch)
    {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("import-batch-{$this->batch->getKey()}"))
                ->releaseAfter(10)
                ->expireAfter(3600),
        ];
    }

    public function handle(): void
    {
        $this->batch->refresh();

        if (! in_array($this->batch->status, [ImportStatus::Pending, ImportStatus::Failed], true)) {
            return;
        }

        $this->batch->update([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
        ]);

        $rows = collect(Excel::toArray([], Storage::path($this->batch->source_file_path))[0] ?? []);
        $headers = collect($rows->shift() ?? [])->map(fn($header) => str($header)->snake()->toString())->all();
        $records = $rows
            ->filter(fn($row) => collect($row)->filter(fn($value) => !is_null($value) && $value !== '')->isNotEmpty())
            ->map(fn(array $row) => array_combine($headers, array_pad($row, count($headers), null)))
            ->values();

        $this->batch->update(['total_rows' => $records->count()]);

        $adapter = app($this->adapter());
        $errors = [];
        $success = 0;

        foreach ($records as $index => $row) {
            try {
                $adapter->import($row, $this->batch->options ?? []);
                $success++;
            } catch (Throwable $exception) {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->implode(' ')
                        : $exception->getMessage(),
                ];
            }

            $this->batch->update([
                'processed_rows' => $index + 1,
                'success_rows' => $success,
                'failed_rows' => count($errors),
                'errors' => $errors,
            ]);
        }

        $this->batch->update([
            'status' => count($errors) > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->batch->update([
            'status' => ImportStatus::Failed,
            'errors' => [['message' => $exception->getMessage()]],
            'finished_at' => now(),
        ]);
    }

    private function adapter(): string
    {
        return match ($this->batch->type->value) {
            'menus' => \Modules\Import\Services\Adapters\MenuImportAdapter::class,
            'orders' => \Modules\Import\Services\Adapters\OrderImportAdapter::class,
            'products' => \Modules\Import\Services\Adapters\ProductImportAdapter::class,
            'users' => \Modules\Import\Services\Adapters\UserImportAdapter::class,
        };
    }
}
