<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\SaasContentItem;
use Modules\Saas\Services\Content\SaasContentCatalogService;
use Modules\Support\ApiResponse;

/**
 * SaaS admin management of the content every tenant consumes: downloads,
 * documentation, videos, support contacts and release notes.
 *
 * Publishing is a separate action from saving, so an operator can stage a new
 * release and turn it on deliberately rather than the moment they hit save.
 */
class SaasContentCatalogController extends Controller
{
    public function __construct(private readonly SaasContentCatalogService $catalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(SaasContentItem::types())],
            'published' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $items = SaasContentItem::query()
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->ofType($type))
            ->when(
                array_key_exists('published', $filters) && $filters['published'] !== null,
                fn ($q) => $q->where('is_published', (bool) $filters['published'])
            )
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($inner) => $inner->where('title', 'like', "%{$term}%")->orWhere('key', 'like', "%{$term}%")
            ))
            ->ordered()
            ->get();

        return ApiResponse::success([
            'items' => $items,
            'types' => SaasContentItem::types(),
            // Tells the console whether config fallback is still in effect, so
            // an empty list is not mistaken for "tenants see nothing".
            'database_backed' => $this->catalog->isDatabaseBacked(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->ensurePublishable($data);

        return ApiResponse::created(
            SaasContentItem::query()->create([
                ...$data,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'published_at' => ($data['is_published'] ?? false) ? now() : null,
            ]),
            message: 'Content item created.'
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = SaasContentItem::query()->findOrFail($id);
        $data = $this->validated($request, $id);
        $this->ensurePublishable([...$item->toArray(), ...$data]);

        $item->update([
            ...$data,
            'updated_by' => $request->user()?->id,
            'published_at' => ($data['is_published'] ?? $item->is_published)
                ? ($item->published_at ?? now())
                : null,
        ]);

        return ApiResponse::updated($item->refresh(), message: 'Content item updated.');
    }

    /**
     * Publish or unpublish. Separate from update so it is one click, and so an
     * item can be pulled from every tenant immediately if a build is bad.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['is_published' => ['required', 'boolean']]);
        $item = SaasContentItem::query()->findOrFail($id);
        if ($data['is_published']) {
            $this->ensurePublishable($item->toArray());
        }

        $item->update([
            'is_published' => $data['is_published'],
            'published_at' => $data['is_published'] ? ($item->published_at ?? now()) : null,
            'updated_by' => $request->user()?->id,
        ]);

        return ApiResponse::updated(
            $item->refresh(),
            message: $data['is_published'] ? 'Published to all restaurants.' : 'Unpublished.'
        );
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:204800'],
        ]);

        $file = $data['file'];
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        $safeName = Str::slug($original) ?: 'download';
        $filename = $safeName.'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(8));
        if ($extension) {
            $filename .= '.'.Str::lower($extension);
        }

        $path = $file->storeAs('saas/downloads', $filename, 'public');

        return ApiResponse::success([
            'url' => Storage::disk('public')->url($path),
            'size' => $this->humanSize((int) $file->getSize()),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'name' => $file->getClientOriginalName(),
        ], message: 'Artifact uploaded.');
    }

    public function destroy(int $id): JsonResponse
    {
        $item = SaasContentItem::query()->findOrFail($id);
        $item->delete();

        return ApiResponse::destroyed(true, message: 'Content item removed.');
    }

    /**
     * Exactly what a tenant would see right now — lets an operator verify a
     * change without logging into a restaurant.
     */
    public function preview(): JsonResponse
    {
        return ApiResponse::success([
            'downloads' => $this->catalog->downloads(),
            'documentation' => $this->catalog->documentation(),
            'support' => $this->catalog->support(),
            'release_notes' => $this->catalog->releaseNotes(),
        ]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(SaasContentItem::types())],
            'key' => [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('saas_content_items', 'key')
                    ->where(fn ($query) => $query->where('type', $request->input('type')))
                    ->ignore($ignoreId),
            ],
            'section' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:80'],
            'version' => ['nullable', 'string', 'max:40'],
            'min_os' => ['nullable', 'string', 'max:80'],
            'size' => ['nullable', 'string', 'max:40'],
            'checksum' => ['nullable', 'string', 'max:128'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'released_at' => ['nullable', 'date'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'content_type' => ['nullable', Rule::in(['guide', 'video', 'troubleshooting', 'faq'])],
            'contact_value' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);
    }

    /**
     * Never advertise a draft or incomplete installer to restaurant sites.
     * Uploading a file and publishing its catalogue record are deliberately
     * separate, but the latter must prove the artifact can actually be used.
     */
    private function ensurePublishable(array $data): void
    {
        if (! ($data['is_published'] ?? false)) {
            return;
        }

        $errors = [];
        if (
            in_array($data['type'] ?? null, [
                SaasContentItem::TYPE_DOWNLOAD,
                SaasContentItem::TYPE_DOCUMENT,
                SaasContentItem::TYPE_VIDEO,
            ], true)
            && blank($data['url'] ?? null)
        ) {
            $errors['url'][] = 'Upload an artifact or enter a valid URL before publishing.';
        }

        if (($data['type'] ?? null) === SaasContentItem::TYPE_DOWNLOAD) {
            foreach ([
                'platform' => 'Select the target app/platform before publishing.',
                'version' => 'Enter the app version before publishing.',
                'checksum' => 'Upload the artifact to generate a SHA-256 checksum before publishing.',
            ] as $field => $message) {
                if (blank($data[$field] ?? null)) {
                    $errors[$field][] = $message;
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = max(0, $bytes);
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $size : number_format($size, 1)).' '.$units[$unit];
    }
}
