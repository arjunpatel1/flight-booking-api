<?php

namespace Modules\Product\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use Modules\Product\Http\Requests\OptimizeImageRequest;
use Modules\Product\Jobs\OptimizeImageJob;
use Modules\Product\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Models\Product;
use Illuminate\Support\Facades\Log;

class ImageOptimizationController extends Controller
{
    private ImageOptimizationService $imageService;

    public function __construct(ImageOptimizationService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * Upload and optimize image for a product.
     */
    public function upload(OptimizeImageRequest $request): JsonResponse
    {
        try {
            $product = Product::find($request->product_id);
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            // Upload and optimize image
            $result = $this->imageService->upload(
                $request->file('image'),
                $product->id
            );

            // Update product with optimized image paths
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

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded and optimized successfully',
                'data' => [
                    'thumbnail_url' => $product->thumbnail_url,
                    'medium_url' => $product->medium_url,
                    'original_url' => $product->original_url,
                    'processing_time_ms' => $result['processing_time_ms'],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Optimize existing product image.
     */
    public function optimize(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        try {
            $product = Product::find($request->product_id);
            if (!$product || !$product->image) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product or image not found'
                ], 404);
            }

            // Get current image path
            $imagePath = $this->getImagePath($product);
            if (!$imagePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Image file not found'
                ], 404);
            }

            // Dispatch optimization job
            OptimizeImageJob::dispatch($imagePath, $product->id, true);

            return response()->json([
                'success' => true,
                'message' => 'Image optimization queued successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Image optimization failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to optimize image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get image optimization status.
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $product = Product::find($request->product_id);

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $product->image_optimization_status,
                'optimized_at' => $product->image_optimized_at,
                'error' => $product->image_optimization_error,
                'is_optimized' => $product->isImageOptimized(),
                'total_size' => $product->getTotalImageSize(),
                'thumbnail_url' => $product->thumbnail_url,
                'medium_url' => $product->medium_url,
                'original_url' => $product->original_url,
            ]
        ]);
    }

    /**
     * Delete all image versions for a product.
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        try {
            $product = Product::find($request->product_id);
            
            // Delete all image versions
            $this->imageService->delete($product->id);

            // Reset product image fields
            $product->update([
                'image_thumbnail_path' => null,
                'image_medium_path' => null,
                'image_original_path' => null,
                'image_original_size' => null,
                'image_medium_size' => null,
                'image_thumbnail_size' => null,
                'image_optimization_status' => null,
                'image_optimized_at' => null,
                'image_optimization_error' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'All image versions deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Image deletion failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current image path from product.
     */
    protected function getImagePath($product): ?string
    {
        if ($product->image) {
            if (str_starts_with($product->image, 'http')) {
                return parse_url($product->image, PHP_URL_PATH);
            }
            
            if (Storage::disk('public')->exists($product->image)) {
                return $product->image;
            }
            
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
}
