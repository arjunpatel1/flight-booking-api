<?php

namespace Modules\Media\Services\Media;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Media\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface MediaServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return Media
     */
    public function getModel(): Media;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param int $skip
     * @return Collection
     */
    public function get(array $filters = [], int $skip = 0): Collection;

    /**
     * Store a newly created media in storage.
     *
     * @param UploadedFile $file
     * @param int|null $mediaId
     * @return Media|null
     */
    public function store(UploadedFile $file, ?int $mediaId = null): ?Media;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param string $name
     * @return Media
     */
    public function update(int $id, string $name): Media;

    /**
     * Store a newly created folder media in storage.
     *
     * @param array $data
     * @return Media
     */
    public function storeFolder(array $data): Media;

    /**
     * Destroy resource's by given id.
     *
     * @param int|array|string $ids
     * @return bool
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public function destroy(int|array|string $ids): bool;

    /**
     * Download the file associated with the given media ID.
     *
     * This method returns a streamed response for downloading a media item
     * of type 'file' based on the provided ID.
     *
     * @param int $id The ID of the media file to download.
     * @return StreamedResponse
     */
    public function downloadFile(int $id): StreamedResponse;
}
