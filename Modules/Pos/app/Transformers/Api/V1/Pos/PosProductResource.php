<?php

namespace Modules\Pos\Transformers\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Models\Product;
use Modules\Support\Money;

/** @mixin Product */
class PosProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->getAttributes();
        $hasResolvedPrice = array_key_exists('pos_resolved_price', $attributes);
        $thumbnail = $this->resolveThumbnailUrl();

        return [
            "id" => $this->id,
            "reference" => $attributes['uuid'] ?? null,
            "sku" => $this->sku,
            "name" => $this->name,
            "thumbnail" => $thumbnail,
            "image_url" => $thumbnail,
            "price" => $this->price,
            "selling_price" => $this->selling_price,
            "base_price" => new Money((float) $this->getRawOriginal('price'), $this->currency),
            "has_special_price" => $hasResolvedPrice ? false : $this->hasSpecialPrice(),
            "price_source" => $attributes['pos_price_source'] ?? 'base',
            "price_type_id" => $attributes['pos_price_type_id'] ?? null,
            "is_new" => $this->isNew(),
            "is_recommended" => (bool) $this->is_recommended,
            "is_manual_best_seller" => (bool) $this->is_best_seller,
            "is_sales_best_seller" => (bool) ($this->is_sales_best_seller ?? false),
            "is_best_seller" => (bool) $this->is_best_seller || (bool) ($this->is_sales_best_seller ?? false),
            "sales_quantity" => (int) ($this->sales_quantity ?? 0),
            "display_priority" => (int) $this->display_priority,
            'is_available' => (bool) $this->is_available,
            'notes' => (string) ($attributes['notes'] ?? ''),
            'search_keywords' => $this->searchKeywords(),
            'memory' => $this->memoryPayload(),
            'allergens' => array_key_exists('allergens', $attributes) ? ($this->allergens ?? []) : [],
            'dietary_labels' => array_key_exists('dietary_labels', $attributes) ? ($this->dietary_labels ?? []) : [],
            // Some lightweight product projections intentionally omit optional
            // dietary metadata. Reading a missing cast attribute through the
            // model throws when Laravel strict mode is enabled, so serialize
            // from the already-inspected attribute bag instead.
            'food_type' => $attributes['food_type'] ?? null,
            "category_ids" => $this->relationLoaded("categories") ? $this->categories->pluck('id') : [],
            "has_options" => (bool) ($this->options_exists ?? ($this->relationLoaded('options') && $this->options->isNotEmpty())),
            "options" => $this->relationLoaded("options")
                ? PosProductOptionResource::collection($this->options)
                : [],
        ];
    }

    private function resolveThumbnailUrl(): ?string
    {
        return $this->thumbnail_url
            ?: ($this->relationLoaded('files') ? $this->thumbnail?->preview_image_url : null);
    }

    /**
     * Deterministic restaurant-memory signals for local POS ranking.
     */
    private function memoryPayload(): array
    {
        $memory = $this->getAttributes()['pos_memory'] ?? [];
        if (! is_array($memory)) {
            $memory = [];
        }

        return [
            'version' => (int) ($memory['version'] ?? 1),
            'shift' => (string) ($memory['shift'] ?? ''),
            'recommendation_score' => (int) ($memory['recommendation_score'] ?? 0),
            'popularity_score' => (int) ($memory['popularity_score'] ?? 0),
            'shift_score' => (int) ($memory['shift_score'] ?? 0),
            'waiter_score' => (int) ($memory['waiter_score'] ?? 0),
            'table_score' => (int) ($memory['table_score'] ?? 0),
            'pairing_score' => (int) ($memory['pairing_score'] ?? 0),
            'restaurant_quantity' => (int) ($memory['restaurant_quantity'] ?? 0),
            'waiter_quantity' => (int) ($memory['waiter_quantity'] ?? 0),
            'table_quantity' => (int) ($memory['table_quantity'] ?? 0),
            'pairing_product_ids' => collect($memory['pairing_product_ids'] ?? [])
                ->map(fn($id) => is_numeric($id) ? (int) $id : null)
                ->filter()
                ->values()
                ->all(),
            'pairings' => collect($memory['pairings'] ?? [])
                ->filter(fn($item) => is_array($item))
                ->map(fn(array $item) => [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'score' => (int) ($item['score'] ?? 0),
                ])
                ->filter(fn(array $item) => $item['product_id'] > 0)
                ->values()
                ->all(),
        ];
    }

    /**
     * Lightweight local-search metadata for POS clients.
     *
     * The waiter apps search this locally, so include only compact strings that
     * reduce typing/scrolling without forcing extra API calls.
     */
    private function searchKeywords(): array
    {
        $keywords = [
            $this->id,
            $this->sku,
            $this->name,
            $this->description,
            $this->notes,
            $this->hsn_code,
        ];

        if (method_exists($this->resource, 'getTranslations')) {
            $keywords = [
                ...$keywords,
                ...array_values((array) $this->getTranslations('name')),
                ...array_values((array) $this->getTranslations('description')),
            ];
        }

        if ($this->relationLoaded('categories')) {
            $keywords = [
                ...$keywords,
                ...$this->categories->pluck('name')->all(),
            ];
        }

        return collect($keywords)
            ->filter(fn($value) => is_scalar($value) && trim((string) $value) !== '')
            ->flatMap(fn($value) => $this->keywordVariants((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Add compact deterministic variants for offline POS search.
     */
    private function keywordVariants(string $value): array
    {
        $raw = trim($value);
        if ($raw === '') {
            return [];
        }

        $normalized = $this->normalizeKeyword($raw);
        $compact = str_replace(' ', '', $normalized);
        $canonical = $this->canonicalKeyword($normalized);
        $acronym = collect(explode(' ', $normalized))
            ->filter()
            ->map(fn(string $token) => mb_substr($token, 0, 1))
            ->implode('');

        return collect([
            $raw,
            $normalized,
            $compact,
            $canonical,
            str_replace(' ', '', $canonical),
            $acronym,
            ])
            ->filter(fn($item) => is_string($item) && trim($item) !== '')
            ->map(fn(string $item) => trim($item))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeKeyword(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?: '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    private function canonicalKeyword(string $value): string
    {
        $map = [
            'panir' => 'paneer',
            'panner' => 'paneer',
            'nan' => 'naan',
            'nun' => 'naan',
            'biriyani' => 'biryani',
            'briyani' => 'biryani',
            'dosha' => 'dosa',
            'masla' => 'masala',
            'msala' => 'masala',
            'chiken' => 'chicken',
            'chikn' => 'chicken',
            'chilli' => 'chili',
            'chilly' => 'chili',
            'manchoorian' => 'manchurian',
            'sezwan' => 'schezwan',
            'shezwan' => 'schezwan',
            'coca' => 'coke',
            'cola' => 'coke',
            'cocacola' => 'coke',
        ];

        return collect(explode(' ', $value))
            ->filter()
            ->map(fn(string $token) => $map[$token] ?? $token)
            ->implode(' ');
    }
}
