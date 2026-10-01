<?php

namespace Modules\Media\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Log;
use Modules\Core\Http\Controllers\Controller;
use Modules\Media\Enum\MediaType;
use Modules\Media\Http\Requests\Api\V1\FolderMediaRequest;
use Modules\Media\Http\Requests\Api\V1\UpdateMediaRequest;
use Modules\Media\Http\Requests\Api\V1\UploadMediaRequest;
use Modules\Media\Services\Media\MediaServiceInterface;
use Modules\Media\Transformers\Api\V1\MediaResource;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    /**
     * Create a new instance of MediaController
     *
     * @param MediaServiceInterface $service
     */
    public function __construct(protected MediaServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Media models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {

        $mime = $request->get('filters', ['mime' => ''])['mime'];

        return ApiResponse::success(
            [
                "files" => MediaResource::collection(
                    $this->service->get(
                        filters: $request->get('filters', []),
                        skip: $request->get('skip', 0),
                    )
                ),
                "max_file_size" => (int)ini_get('upload_max_filesize'),
                "accepted_files" => $mime ? implode(",", array_map(fn($mime) => "{$mime}/*", explode(",", $mime))) : null
            ]
        );
    }

    /**
     * This method stores the provided data into storage for the Media model.
     *
     * @param UploadMediaRequest $request
     * @return JsonResponse
     */
    public function store(UploadMediaRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new MediaResource($this->service->store(
                file: $request->file('file'),
                mediaId: $request->folder_id,
            )),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Media model.
     *
     * @param UpdateMediaRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateMediaRequest $request, int $id): JsonResponse
    {
        $media = $this->service->update($id, $request->name);

        return ApiResponse::success(
            message: __(
                "admin::messages.resource_updated",
                ["resource" => __("media::media." . ($media->type == MediaType::Folder ? 'folder' : 'file'))]
            )
        );
    }

    /**
     * This method store folder the provided data for the Media model.
     *
     * @param FolderMediaRequest $request
     * @return JsonResponse
     */
    public function storeFolder(FolderMediaRequest $request): JsonResponse
    {
        $this->service->storeFolder($request->validated());

        return ApiResponse::success(
            message: __("admin::messages.resource_created", ["resource" => __("media::media.folder")])
        );
    }

    /**
     * This method deletes the Media model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

    /**
     * Download the file associated with the given media ID.
     *
     * This method returns a streamed response for downloading a media item
     * of type 'file' based on the provided ID.
     *
     * @param int $id The ID of the media file to download.
     * @return StreamedResponse
     */
    public function download(int $id): StreamedResponse
    {
        return $this->service->downloadFile($id);
    }

}
