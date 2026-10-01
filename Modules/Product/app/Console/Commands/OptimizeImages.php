<?php

namespace Modules\Product\Console\Commands;

use Modules\Product\Jobs\OptimizeImageJob;
use Modules\Product\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Product\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OptimizeImages extends Command
{
    protected $signature = 'images:optimize 
                            {--product-id= : Optimize images for specific product ID}
                            {--batch : Process images in batches}
                            {--batch-size=50 : Number of images to process per batch}
                            {--dry-run : Show what would be optimized without processing}
                            {--force : Re-optimize already optimized images}
                            {--verbose : Show detailed output}';

    protected $description = 'Optimize product images - convert to WebP and generate multiple sizes';

    protected ImageOptimizationService $imageService;
    protected int $totalProcessed = 0;
    protected int $totalFailed = 0;
    protected int $totalSkipped = 0;
    protected int $totalSpaceSaved = 0;

    public function __construct(ImageOptimizationService $imageService)
    {
        parent::__construct();
        $this->imageService = $imageService;
    }

    public function handle(): int
    {
        $this->info('Starting image optimization...');
        $this->info('=====================================');

        $startTime = microtime(true);

        if ($this->option('product-id')) {
            $this->optimizeSingleProduct((int)$this->option('product-id'));
        } else {
            $this->optimizeAllProducts();
        }

        $totalTime = round((microtime(true) - $startTime), 2);

        $this->info('=====================================');
        $this->info('Image Optimization Summary:');
        $this->info("Total Processed: {$this->totalProcessed}");
        $this->info("Total Failed: {$this->totalFailed}");
        $this->info("Total Skipped: {$this->totalSkipped}");
        $this->info("Space Saved: " . $this->formatBytes($this->totalSpaceSaved));
        $this->info("Total Time: {$totalTime}s");

        return $this->totalFailed > 0 ? 1 : 0;
    }

    protected function optimizeSingleProduct(int $productId): void
    {
        $this->info("Optimizing images for product ID: {$productId}");

        $product = Product::find($productId);
        if (!$product) {
            $this->error("Product not found: {$productId}");
            return;
        }

        if (!$product->image) {
            $this->warn("Product has no image: {$productId}");
            return;
        }

        $this->processProductImage($product);
    }

    protected function optimizeAllProducts(): void
    {
        $query = Product::whereNotNull('image');

        if (!$this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('image_optimization_status')
                  ->orWhere('image_optimization_status', '!=', 'completed');
            });
        }

        $products = $query->get();
        $totalProducts = $products->count();

        $this->info("Found {$totalProducts} products to optimize");

        if ($totalProducts === 0) {
            $this->info('No images to optimize.');
            return;
        }

        if ($this->option('batch')) {
            $this->processInBatches($products);
        } else {
            $this->processSequentially($products);
        }
    }

    protected function processSequentially($products): void
    {
        $progressBar = $this->output->createProgressBar($products->count());
        $progressBar->start();

        foreach ($products as $product) {
            $this->processProductImage($product);
            $progressBar->advance();
        }

        $progressBar->finish();
    }

    protected function processInBatches($products): void
    {
        $batchSize = (int)$this->option('batch-size', 50);
        $batches = $products->chunk($batchSize);

        foreach ($batches as $index => $batch) {
            $this->info("Processing batch " . ($index + 1) . " of " . $batches->count());

            foreach ($batch as $product) {
                $this->processProductImage($product);
            }

            $this->info("Batch " . ($index + 1) . " completed. Waiting 2 seconds...");
            sleep(2);
        }
    }

    protected function processProductImage($product): void
    {
        try {
            if ($this->option('dry-run')) {
                $this->line("Would optimize: Product ID {$product->id} - {$product->image}");
                $this->totalSkipped++;
                return;
            }

            // Check if already optimized
            if (!$this->option('force') && $product->image_optimization_status === 'completed') {
                $this->totalSkipped++;
                return;
            }

            // Get current image path
            $imagePath = $this->getCurrentImagePath($product);
            if (!$imagePath || !Storage::disk('public')->exists($imagePath)) {
                $this->warn("Image file not found for product {$product->id}");
                $this->totalFailed++;
                return;
            }

            // Get original size
            $originalSize = Storage::disk('public')->size($imagePath);

            // Update status to processing
            $product->update([
                'image_optimization_status' => 'processing',
                'image_optimization_error' => null,
            ]);

            // Optimize image
            $result = $this->imageService->optimize(
                storage_path('app/public/' . $imagePath),
                $product->id
            );

            // Calculate space saved
            $totalOptimizedSize = 0;
            foreach ($result['paths'] as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    $totalOptimizedSize += Storage::disk('public')->size($path);
                }
            }

            $spaceSaved = max(0, $originalSize - $totalOptimizedSize);
            $this->totalSpaceSaved += $spaceSaved;

            // Update product with optimized paths
            $product->update([
                'image_thumbnail_path' => $result['paths']['thumbnail'] ?? null,
                'image_medium_path' => $result['paths']['medium'] ?? null,
                'image_original_path' => $result['paths']['original'] ?? null,
                'image_optimization_status' => 'completed',
                'image_optimized_at' => now(),
                'image_optimization_error' => null,
            ]);

            // Update file sizes
            foreach ($result['paths'] as $size => $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    $sizeField = 'image_' . $size . '_size';
                    $product->$sizeField = Storage::disk('public')->size($path);
                }
            }
            $product->save();

            $this->totalProcessed++;

            if ($this->option('verbose')) {
                $this->line("✓ Product {$product->id}: " . $this->formatBytes($spaceSaved) . " saved");
            }

        } catch (\Exception $e) {
            $this->error("Failed to optimize product {$product->id}: " . $e->getMessage());
            
            $product->update([
                'image_optimization_status' => 'failed',
                'image_optimization_error' => $e->getMessage(),
            ]);

            $this->totalFailed++;
        }
    }

    protected function getCurrentImagePath($product): ?string
    {
        // Try to get the current image path from the image field
        if ($product->image) {
            // Check if it's already a full path or just filename
            if (str_starts_with($product->image, 'http')) {
                // It's a URL, try to extract path
                return parse_url($product->image, PHP_URL_PATH);
            }
            
            // Check if file exists in storage
            if (Storage::disk('public')->exists($product->image)) {
                return $product->image;
            }
            
            // Try common paths
            $possiblePaths = [
                "products/{$product->image}",
                "uploads/products/{$product->image}",
                "uploads/{$product->image}",
            ];
            
            foreach ($possiblePaths as $path) {
                if (Storage::disk('public')->exists($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        }

        return '0 bytes';
    }
}
