<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ImageOptimizationService
{
    protected ImageManager $imageManager;
    protected array $sizes;
    protected int $quality;
    protected string $disk;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
        $this->sizes = [
            'thumbnail' => [
                'width' => 150,
                'height' => 150,
                'fit' => 'cover'
            ],
            'medium' => [
                'width' => 400,
                'height' => 400,
                'fit' => 'cover'
            ],
            'original' => [
                'width' => null,
                'height' => null,
                'fit' => null
            ]
        ];
        $this->quality = config('image.quality', 85);
        $this->disk = config('image.disk', 'public');
    }

    /**
     * Upload and optimize image
     */
    public function upload(UploadedFile $file, int $productId): array
    {
        try {
            // Validate file
            $this->validateFile($file);

            // Generate unique filename
            $filename = $this->generateFilename($file);
            
            // Store original temporarily
            $tempPath = $file->storeAs('temp', $filename, 'local');
            $fullTempPath = storage_path('app/' . $tempPath);

            // Process image
            $result = $this->processImage($fullTempPath, $productId, $filename);

            // Clean up temp file
            unlink($fullTempPath);

            return $result;
        } catch (\Exception $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Optimize existing image
     */
    public function optimize(string $imagePath, int $productId): array
    {
        try {
            $filename = basename($imagePath);
            return $this->processImage($imagePath, $productId, $filename);
        } catch (\Exception $e) {
            Log::error('Image optimization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process image - generate all sizes and convert to WebP
     */
    protected function processImage(string $imagePath, int $productId, string $filename): array
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($this->sizes as $size => $config) {
            try {
                $result = $this->generateSize($imagePath, $productId, $filename, $size, $config);
                $results[$size] = $result;
            } catch (\Exception $e) {
                Log::error("Failed to generate {$size}: " . $e->getMessage());
                $results[$size] = null;
            }
        }

        $processingTime = round((microtime(true) - $startTime) * 1000);

        return [
            'paths' => $results,
            'processing_time_ms' => $processingTime,
            'filename' => $filename
        ];
    }

    /**
     * Generate specific image size
     */
    protected function generateSize(string $imagePath, int $productId, string $filename, string $size, array $config): ?string
    {
        // Use GD library directly for reliability
        return $this->generateSizeWithGD($imagePath, $productId, $filename, $size, $config);
    }

    /**
     * Generate image size using GD library
     */
    protected function generateSizeWithGD(string $imagePath, int $productId, string $filename, string $size, array $config): ?string
    {
        // Get image info
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return null;
        }

        // Create image from file
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($imagePath);
                break;
            default:
                return null;
        }

        if (!$source) {
            return null;
        }

        // Get original dimensions
        $originalWidth = imagesx($source);
        $originalHeight = imagesy($source);

        // Calculate new dimensions
        if ($config['width'] && $config['height']) {
            if ($config['fit'] === 'cover') {
                $ratio = max($config['width'] / $originalWidth, $config['height'] / $originalHeight);
                $newWidth = $config['width'];
                $newHeight = $config['height'];
            } else {
                $ratio = min($config['width'] / $originalWidth, $config['height'] / $originalHeight);
                $newWidth = round($originalWidth * $ratio);
                $newHeight = round($originalHeight * $ratio);
            }
        } else {
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
        }

        // Create new image
        $destination = imagecreatetruecolor($newWidth, $newHeight);
        
        // Handle transparency for PNG
        if ($imageInfo[2] == IMAGETYPE_PNG) {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
            imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // Resize image
        if ($config['fit'] === 'cover') {
            $tempWidth = round($originalWidth * $ratio);
            $tempHeight = round($originalHeight * $ratio);
            $temp = imagecreatetruecolor($tempWidth, $tempHeight);
            imagecopyresampled($temp, $source, 0, 0, 0, 0, $tempWidth, $tempHeight, $originalWidth, $originalHeight);
            
            // Crop to center
            $x = ($tempWidth - $newWidth) / 2;
            $y = ($tempHeight - $newHeight) / 2;
            imagecopy($destination, $temp, 0, 0, $x, $y, $newWidth, $newHeight);
            imagedestroy($temp);
        } else {
            imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        }

        // Convert to WebP
        $webpFilename = $this->getWebpFilename($filename);
        $storagePath = $this->getStoragePath($productId, $size, $webpFilename);
        
        // Ensure directory exists
        $directory = dirname(storage_path('app/' . $this->disk . '/' . $storagePath));
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Save as WebP
        imagewebp($destination, storage_path('app/' . $this->disk . '/' . $storagePath), $this->quality);

        // Clean up
        imagedestroy($source);
        imagedestroy($destination);

        return $storagePath;
    }

    /**
     * Optimize image using Spatie
     */
    protected function optimizeImage(string $path): void
    {
        // GD library already handles WebP optimization in generateSizeWithGD
        // This method is kept for future enhancements if needed
        return;
    }

    /**
     * Get image URL for specific size
     */
    public function getUrl(int $productId, string $size = 'medium'): ?string
    {
        if (!isset($this->sizes[$size])) {
            return null;
        }

        $path = $this->getStoragePath($productId, $size, '*.webp');
        
        if (Storage::disk($this->disk)->exists($path)) {
            $files = Storage::disk($this->disk)->files($this->getStoragePath($productId, $size));
            if (!empty($files)) {
                return Storage::disk($this->disk)->url($files[0]);
            }
        }

        return null;
    }

    /**
     * Get all image URLs for a product
     */
    public function getAllUrls(int $productId): array
    {
        $urls = [];
        
        foreach (array_keys($this->sizes) as $size) {
            $urls[$size] = $this->getUrl($productId, $size);
        }

        return $urls;
    }

    /**
     * Delete all image versions for a product
     */
    public function delete(int $productId): bool
    {
        try {
            foreach (array_keys($this->sizes) as $size) {
                $path = $this->getStoragePath($productId, $size);
                if (Storage::disk($this->disk)->exists($path)) {
                    Storage::disk($this->disk)->deleteDirectory($path);
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete images: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate uploaded file
     */
    protected function validateFile(UploadedFile $file): void
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxSize = 10 * 1024 * 1024; // 10MB

        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: ' . implode(', ', $allowedTypes));
        }

        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException('File size exceeds maximum of 10MB');
        }
    }

    /**
     * Generate unique filename
     */
    protected function generateFilename(UploadedFile $file): string
    {
        return Str::random(40) . '.' . $file->getClientOriginalExtension();
    }

    /**
     * Get WebP filename
     */
    protected function getWebpFilename(string $filename): string
    {
        return pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    }

    /**
     * Get storage path for specific size
     */
    protected function getStoragePath(int $productId, string $size, string $filename = ''): string
    {
        return "products/{$size}/{$productId}/{$filename}";
    }

    /**
     * Get image info
     */
    public function getImageInfo(string $path): array
    {
        $fullPath = storage_path('app/' . $this->disk . '/' . $path);
        
        if (!file_exists($fullPath)) {
            return [];
        }

        $imageInfo = getimagesize($fullPath);
        $fileSize = filesize($fullPath);

        return [
            'width' => $imageInfo[0] ?? null,
            'height' => $imageInfo[1] ?? null,
            'mime_type' => $imageInfo['mime'] ?? null,
            'size_bytes' => $fileSize,
            'size_kb' => round($fileSize / 1024, 2),
        ];
    }

    /**
     * Batch optimize images
     */
    public function batchOptimize(array $imagePaths, int $productId): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $totalProcessingTime = 0;

        foreach ($imagePaths as $index => $imagePath) {
            try {
                $result = $this->optimize($imagePath, $productId);
                $results[$index] = [
                    'status' => 'success',
                    'data' => $result
                ];
                $successCount++;
                $totalProcessingTime += $result['processing_time_ms'];
            } catch (\Exception $e) {
                $results[$index] = [
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
                $failureCount++;
            }
        }

        return [
            'total' => count($imagePaths),
            'success' => $successCount,
            'failed' => $failureCount,
            'total_processing_time_ms' => $totalProcessingTime,
            'results' => $results
        ];
    }
}
