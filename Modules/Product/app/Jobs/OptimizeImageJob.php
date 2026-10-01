<?php

namespace Modules\Product\Jobs;

use Modules\Product\Services\ImageOptimizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Product\Models\Product;

class OptimizeImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public array $backoff = [30, 60, 120]; // Exponential backoff

    protected string $imagePath;
    protected int $productId;
    protected bool $isExistingImage;

    public function __construct(string $imagePath, int $productId, bool $isExistingImage = false)
    {
        $this->imagePath = $imagePath;
        $this->productId = $productId;
        $this->isExistingImage = $isExistingImage;
    }

    public function handle(ImageOptimizationService $imageService): void
    {
        try {
            $startTime = microtime(true);

            // Update status to processing
            if ($this->isExistingImage) {
                $this->updateProductStatus('processing');
            }

            // Optimize image
            $result = $imageService->optimize($this->imagePath, $this->productId);

            $processingTime = round((microtime(true) - $startTime) * 1000);

            // Update product with optimized image paths
            $this->updateProductWithOptimizedImages($result);

            // Update status to completed
            if ($this->isExistingImage) {
                $this->updateProductStatus('completed', null, $processingTime);
            }

            Log::info("Image optimization completed for product {$this->productId}", [
                'processing_time_ms' => $processingTime,
                'paths' => $result['paths']
            ]);

        } catch (\Exception $e) {
            Log::error("Image optimization failed for product {$this->productId}: " . $e->getMessage());
            
            // Update status to failed
            if ($this->isExistingImage) {
                $this->updateProductStatus('failed', $e->getMessage());
            }

            $this->fail($e);
        }
    }

    protected function updateProductWithOptimizedImages(array $result): void
    {
        $product = Product::find($this->productId);
        if (!$product) {
            return;
        }

        $paths = $result['paths'];
        
        $product->update([
            'image_thumbnail_path' => $paths['thumbnail'] ?? null,
            'image_medium_path' => $paths['medium'] ?? null,
            'image_original_path' => $paths['original'] ?? null,
            'image_optimization_status' => 'completed',
            'image_optimized_at' => now(),
            'image_optimization_error' => null,
        ]);

        // Update file sizes
        foreach ($paths as $size => $path) {
            if ($path) {
                $sizeField = 'image_' . $size . '_size';
                $imageInfo = $this->getImageInfo($path);
                if ($imageInfo) {
                    $product->$sizeField = $imageInfo['size_bytes'];
                }
            }
        }

        $product->save();
    }

    protected function getImageInfo(string $path): ?array
    {
        $fullPath = storage_path('app/public/' . $path);
        if (!file_exists($fullPath)) {
            return null;
        }

        return [
            'size_bytes' => filesize($fullPath),
        ];
    }

    protected function updateProductStatus(string $status, ?string $error = null, ?int $processingTime = null): void
    {
        $product = Product::find($this->productId);
        if (!$product) {
            return;
        }

        $product->update([
            'image_optimization_status' => $status,
            'image_optimization_error' => $error,
        ]);

        if ($status === 'completed' && $processingTime) {
            // Could store processing time in image_optimizations table
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("OptimizeImageJob failed for product {$this->productId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
