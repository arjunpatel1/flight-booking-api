<?php

namespace Modules\Product\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Product\Jobs\OptimizeImageJob;
use Modules\Product\Models\Product;

/**
 * Bridges the media-library picker (files pivot, zone "thumbnail") to the
 * image-optimization pipeline so the POS app is served small optimized
 * thumbnails instead of the full-size original.
 */
class ProductThumbnailOptimizer
{
    /**
     * Re-entrancy guard. The optimize job updates the product (image_* columns),
     * which re-fires the `saved` hook. On a sync queue that update runs inline
     * within the same HTTP request (where `files` is still present), so without
     * this flag it would recurse forever. Safe for async too (the flag simply
     * spans the brief dispatch call).
     */
    private static bool $running = false;

    /**
     * Queue (or clear) thumbnail optimization from the current request's
     * `files.thumbnail`.
     *
     * Called from the Product `saved` hook, so it must be a no-op outside an
     * HTTP form save.
     */
    public static function syncFromRequest(Product $product): void
    {
        if (self::$running || ! request()->has('files')) {
            return;
        }

        $thumbnailId = request('files.thumbnail');

        if (empty($thumbnailId)) {
            self::clear($product);

            return;
        }

        $mediaId = (int) (is_array($thumbnailId) ? Arr::first($thumbnailId) : $thumbnailId);

        self::queue($product, $mediaId);
    }

    /**
     * Dispatch an optimization job for a picked media image.
     */
    public static function queue(Product $product, int $mediaId, bool $sync = false): void
    {
        if (self::$running) {
            return;
        }

        $media = Media::query()->withoutGlobalScopes()->find($mediaId);

        if (! $media || ! $media->isImage()) {
            return;
        }

        // The optimizer reads the source via GD from a local filesystem path.
        $sourcePath = Storage::disk($media->disk)->path($media->path);

        if (! is_file($sourcePath)) {
            return;
        }

        self::$running = true;

        try {
            $sync
                ? OptimizeImageJob::dispatchSync($sourcePath, $product->id, true)
                : OptimizeImageJob::dispatch($sourcePath, $product->id, true);
        } finally {
            self::$running = false;
        }
    }

    /**
     * Drop stale optimized derivatives when the thumbnail is removed.
     * Uses a quiet update so it does not re-trigger the saved hook.
     */
    public static function clear(Product $product): void
    {
        if (is_null($product->image_thumbnail_path)
            && is_null($product->image_medium_path)
            && is_null($product->image_original_path)) {
            return;
        }

        $product->updateQuietly([
            'image_thumbnail_path' => null,
            'image_medium_path' => null,
            'image_original_path' => null,
            'image_optimization_status' => null,
            'image_optimized_at' => null,
            'image_optimization_error' => null,
        ]);
    }
}
