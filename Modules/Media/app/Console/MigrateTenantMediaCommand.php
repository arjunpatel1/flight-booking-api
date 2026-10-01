<?php

namespace Modules\Media\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Branch\Models\Branch;
use Modules\Media\Enum\MediaType;
use Modules\Media\Models\Media;
use Modules\User\Models\User;

class MigrateTenantMediaCommand extends Command
{
    protected $signature = 'saas:migrate-media
        {--dry-run : Show what would move without changing files or database rows}
        {--copy : Copy files instead of moving them}
        {--force : Reprocess tenant media paths too}';

    protected $description = 'Assign legacy media to tenants and move non-image files out of public storage.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $copyOnly = (bool) $this->option('copy');
        $force = (bool) $this->option('force');

        $moved = 0;
        $skipped = 0;
        $missing = 0;

        Media::query()
            ->withoutGlobalScopes()
            ->where('type', MediaType::File->value)
            ->whereNotNull('path')
            ->orderBy('id')
            ->chunkById(100, function ($mediaRows) use ($dryRun, $copyOnly, $force, &$moved, &$skipped, &$missing) {
                foreach ($mediaRows as $media) {
                    $path = ltrim((string) $media->getRawOriginal('path'), '/');

                    $tenantId = $this->resolveTenantId($media);
                    if (! $tenantId) {
                        $this->warn("Skipped media #{$media->id}: tenant could not be resolved.");
                        $skipped++;
                        continue;
                    }

                    $sourceDisk = $media->disk ?: config('media.disk', 'public');
                    $targetDisk = $media->isImage()
                        ? config('media.disk', 'public')
                        : config('media.private_disk', 'local');
                    $target = 'tenants/'.$tenantId.'/media/'.basename($path);
                    $diskNeedsMigration = $sourceDisk !== $targetDisk;
                    $pathNeedsMigration = $path !== $target
                        && ($force || $diskNeedsMigration || str_starts_with($path, 'media/'));
                    $ownerNeedsMigration = (int) $media->tenant_id !== $tenantId;

                    if (! $pathNeedsMigration && ! $diskNeedsMigration && ! $ownerNeedsMigration) {
                        $skipped++;
                        continue;
                    }

                    $nextPath = $pathNeedsMigration ? $target : $path;
                    $nextDisk = $diskNeedsMigration ? $targetDisk : $sourceDisk;
                    $needsFileMove = $pathNeedsMigration || $diskNeedsMigration;

                    $this->line(($dryRun ? '[dry-run] ' : '')."media #{$media->id}: tenant={$tenantId}, {$sourceDisk}:{$path} -> {$nextDisk}:{$nextPath}");

                    if ($needsFileMove && ! Storage::disk($sourceDisk)->exists($path)) {
                        $this->warn("Missing file for media #{$media->id}: {$sourceDisk}:{$path}");
                        $missing++;

                        if (! $dryRun && $ownerNeedsMigration) {
                            $media->forceFill(['tenant_id' => $tenantId])->save();
                        }

                        continue;
                    }

                    if (! $dryRun) {
                        if ($needsFileMove) {
                            Storage::disk($nextDisk)->makeDirectory(dirname($nextPath));

                            if ($sourceDisk === $nextDisk) {
                                $copyOnly
                                    ? Storage::disk($sourceDisk)->copy($path, $nextPath)
                                    : Storage::disk($sourceDisk)->move($path, $nextPath);
                            } else {
                                $contents = Storage::disk($sourceDisk)->readStream($path);
                                if (! is_resource($contents) || ! Storage::disk($nextDisk)->writeStream($nextPath, $contents)) {
                                    if (is_resource($contents)) {
                                        fclose($contents);
                                    }
                                    $this->error("Failed to migrate media #{$media->id}; database row was not changed.");
                                    continue;
                                }
                                fclose($contents);

                                if (! $copyOnly) {
                                    Storage::disk($sourceDisk)->delete($path);
                                }
                            }
                        }

                        $media->forceFill([
                            'tenant_id' => $tenantId,
                            'disk' => $nextDisk,
                            'path' => $nextPath,
                        ])->save();
                    }

                    $moved++;
                }
            });

        $this->info("Media migration complete. moved={$moved}, skipped={$skipped}, missing={$missing}, dry_run=".($dryRun ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function resolveTenantId(Media $media): ?int
    {
        if ($media->tenant_id) {
            return (int) $media->tenant_id;
        }

        if (Schema::hasColumn('media', 'created_by') && $media->created_by) {
            $tenantId = User::query()->whereKey($media->created_by)->value('tenant_id');
            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        $linked = DB::table('model_files')
            ->where('media_id', $media->id)
            ->get(['model_type', 'model_id']);

        foreach ($linked as $row) {
            $tenantId = $this->tenantFromLinkedModel((string) $row->model_type, (int) $row->model_id);
            if ($tenantId) {
                return $tenantId;
            }
        }

        $singleTenant = DB::table('tenants')->count() === 1
            ? DB::table('tenants')->value('id')
            : null;

        return $singleTenant ? (int) $singleTenant : null;
    }

    private function tenantFromLinkedModel(string $modelType, int $modelId): ?int
    {
        $class = Relation::getMorphedModel($modelType) ?: $modelType;

        if (! class_exists($class) || ! is_subclass_of($class, EloquentModel::class)) {
            return null;
        }

        /** @var EloquentModel|null $model */
        $model = $class::query()->withoutGlobalScopes()->find($modelId);
        if (! $model) {
            return null;
        }

        if (array_key_exists('tenant_id', $model->getAttributes()) && $model->getAttribute('tenant_id')) {
            return (int) $model->getAttribute('tenant_id');
        }

        if (array_key_exists('branch_id', $model->getAttributes()) && $model->getAttribute('branch_id')) {
            return (int) Branch::query()
                ->withoutGlobalScopes()
                ->whereKey($model->getAttribute('branch_id'))
                ->value('tenant_id') ?: null;
        }

        if (array_key_exists('created_by', $model->getAttributes()) && $model->getAttribute('created_by')) {
            return (int) User::query()
                ->withoutGlobalScopes()
                ->whereKey($model->getAttribute('created_by'))
                ->value('tenant_id') ?: null;
        }

        return null;
    }
}
