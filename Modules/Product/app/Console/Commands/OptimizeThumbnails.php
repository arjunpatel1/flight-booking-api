<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Models\Product;
use Modules\Product\Support\ProductThumbnailOptimizer;

/**
 * Backfill optimized POS thumbnails for products whose image was set through
 * the media-library picker (files pivot, zone "thumbnail") and therefore never
 * ran through the optimizer.
 */
class OptimizeThumbnails extends Command
{
    protected $signature = 'products:optimize-thumbnails
                            {--product-id= : Only optimize a single product}
                            {--force : Re-optimize products that already have a thumbnail}
                            {--sync : Run the optimization inline instead of on the queue}';

    protected $description = 'Generate optimized POS thumbnails for products whose image was picked from the media library';

    public function handle(): int
    {
        $query = Product::query()
            ->withoutGlobalScopes()
            ->with('files')
            ->whereHas('files', fn ($q) => $q->where('model_files.zone', 'thumbnail'));

        if ($id = $this->option('product-id')) {
            $query->whereKey((int) $id);
        }

        if (! $this->option('force')) {
            $query->whereNull('image_thumbnail_path');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No products need thumbnail optimization.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info("Optimizing thumbnails for {$total} product(s)" . ($sync ? ' (inline)...' : ' (queued)...'));

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $processed = 0;

        $query->chunkById(100, function ($products) use (&$processed, $bar, $sync) {
            foreach ($products as $product) {
                $media = $product->thumbnail; // files eager-loaded => Media|null

                if ($media) {
                    ProductThumbnailOptimizer::queue($product, (int) $media->id, $sync);
                    $processed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info("Optimized {$processed} product thumbnail(s).");
        } else {
            $this->info("Queued {$processed} optimization job(s). Make sure a queue worker is running.");
        }

        return self::SUCCESS;
    }
}
