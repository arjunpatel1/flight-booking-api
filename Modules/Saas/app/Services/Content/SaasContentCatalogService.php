<?php

namespace Modules\Saas\Services\Content;

use Illuminate\Support\Collection;
use Modules\Saas\Models\SaasContentItem;

/**
 * The SaaS-managed content catalogue consumed by every tenant workspace.
 *
 * Reads published rows from `saas_content_items` and falls back to
 * `config('saas.workspace.*')` **per content type** when a type has no
 * published rows. That fallback is what keeps this backward compatible: an
 * installation that has not yet migrated its catalogue into the database keeps
 * showing exactly what it showed before, and each type flips over
 * independently as it is populated.
 *
 * Nothing here is tenant-scoped — the catalogue is platform-wide by design.
 */
class SaasContentCatalogService
{
    /**
     * Downloads, in the shape the tenant Download Center already expects.
     */
    public function downloads(): array
    {
        // Legacy rows could be marked published before an upload completed.
        // They must not hide configured downloads or render dead tenant cards.
        $items = $this->published(SaasContentItem::TYPE_DOWNLOAD)
            ->filter(fn (SaasContentItem $item) => $item->isDownloadable())
            ->values();

        if ($items->isEmpty()) {
            return $this->configDownloads();
        }

        return $items->map(fn (SaasContentItem $item) => [
            'key' => $item->key,
            'name' => $item->title,
            'summary' => $item->summary,
            'platform' => $item->platform,
            'icon' => $item->icon ?: 'tabler-download',
            'url' => $item->url,
            'version' => $item->version,
            'released_at' => optional($item->released_at)->toIso8601String(),
            'min_os' => $item->min_os,
            'size' => $item->size,
            'checksum' => $item->checksum,
            'release_notes' => $item->release_notes ?: $this->latestReleaseNoteFor($item->key),
            'status' => $item->isDownloadable() ? 'available' : 'coming_soon',
            'is_downloadable' => $item->isDownloadable(),
        ])->values()->all();
    }

    /**
     * Documentation grouped into sections, videos included as articles of
     * type `video` so a single searchable surface covers both.
     */
    public function documentation(): array
    {
        $items = $this->published(SaasContentItem::TYPE_DOCUMENT)
            ->merge($this->published(SaasContentItem::TYPE_VIDEO))
            ->sortBy([['section', 'asc'], ['sort_order', 'asc']]);

        if ($items->isEmpty()) {
            return $this->configDocumentation();
        }

        $sectionMeta = collect(config('saas.workspace.documentation', []))
            ->keyBy('key');

        return $items
            ->groupBy(fn (SaasContentItem $item) => $item->section ?: 'getting_started')
            ->map(fn (Collection $group, string $section) => [
                'key' => $section,
                'title' => $sectionMeta[$section]['title'] ?? ucwords(str_replace('_', ' ', $section)),
                'icon' => $sectionMeta[$section]['icon'] ?? 'tabler-book',
                'articles' => $group->map(fn (SaasContentItem $item) => [
                    'title' => $item->title,
                    'url' => $item->url,
                    'minutes' => (int) ($item->minutes ?: 5),
                    'type' => $item->content_type
                        ?: ($item->type === SaasContentItem::TYPE_VIDEO ? 'video' : 'guide'),
                    'is_available' => filled($item->url),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Support contacts. Managed rows override the config value of the same key,
     * so a support number can be changed without a deploy.
     */
    public function support(): array
    {
        $support = (array) config('saas.workspace.support', []);
        $managed = $this->published(SaasContentItem::TYPE_SUPPORT_CONTACT);

        foreach ($managed as $item) {
            if (filled($item->contact_value ?: $item->url)) {
                $support[$item->key] = $item->contact_value ?: $item->url;
            }
        }

        return $support;
    }

    /**
     * Release notes newest first, for the admin console and the tenant
     * "What's new" surface.
     */
    public function releaseNotes(int $limit = 20): array
    {
        return $this->published(SaasContentItem::TYPE_RELEASE_NOTE)
            ->sortByDesc(fn (SaasContentItem $item) => $item->released_at ?? $item->created_at)
            ->take($limit)
            ->map(fn (SaasContentItem $item) => [
                'key' => $item->key,
                'title' => $item->title,
                'summary' => $item->summary,
                'version' => $item->version,
                'body' => $item->release_notes,
                'released_at' => optional($item->released_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Stable marker for the latest tenant-visible content. Tenant UIs use this
     * to notify restaurant admins once per published release/update.
     */
    public function revision(): string
    {
        $latest = SaasContentItem::query()
            ->published()
            ->max('updated_at');

        if ($latest) {
            return 'db-'.sha1((string) $latest);
        }

        return 'config-'.sha1(json_encode([
            'artifacts' => config('saas.workspace.artifacts', []),
            'documentation' => config('saas.workspace.documentation', []),
            'support' => config('saas.workspace.support', []),
        ]));
    }

    /**
     * Small "what changed" list for tenant admins. Kept intentionally compact
     * so the layout can show it without loading the full catalogue UI.
     */
    public function updates(int $limit = 5): array
    {
        return SaasContentItem::query()
            ->published()
            ->whereIn('type', [SaasContentItem::TYPE_DOWNLOAD, SaasContentItem::TYPE_RELEASE_NOTE])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (SaasContentItem $item) => [
                'key' => $item->key,
                'type' => $item->type,
                'title' => $item->title,
                'summary' => $item->summary,
                'version' => $item->version,
                'published_at' => optional($item->published_at)->toIso8601String(),
                'updated_at' => optional($item->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether any type has been migrated into the database yet. Used by the
     * admin console to explain that config fallback is still in effect.
     */
    public function isDatabaseBacked(): bool
    {
        return SaasContentItem::query()->published()->exists();
    }

    private function published(string $type): Collection
    {
        return SaasContentItem::query()
            ->published()
            ->ofType($type)
            ->ordered()
            ->get();
    }

    /**
     * Newest release note matching a download key, so a download can show
     * "what's new" without duplicating the text on both records.
     */
    private function latestReleaseNoteFor(string $key): ?string
    {
        return SaasContentItem::query()
            ->published()
            ->ofType(SaasContentItem::TYPE_RELEASE_NOTE)
            ->where('key', 'like', "{$key}%")
            ->orderByDesc('released_at')
            ->value('release_notes');
    }

    /**
     * Pre-database behaviour, preserved verbatim.
     */
    private function configDownloads(): array
    {
        return collect((array) config('saas.workspace.artifacts', []))
            ->map(function (array $artifact) {
                $url = $artifact['url'] ?? null;

                return [
                    ...$artifact,
                    'url' => $url,
                    'status' => blank($url) ? ($artifact['status'] ?? 'coming_soon') : ($artifact['status'] ?? 'available'),
                    'is_downloadable' => filled($url) && ($artifact['status'] ?? 'available') === 'available',
                ];
            })
            ->values()
            ->all();
    }

    private function configDocumentation(): array
    {
        return collect((array) config('saas.workspace.documentation', []))
            ->map(fn (array $section) => [
                ...$section,
                'articles' => collect($section['articles'] ?? [])
                    ->map(fn (array $article) => [...$article, 'is_available' => filled($article['url'] ?? null)])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
