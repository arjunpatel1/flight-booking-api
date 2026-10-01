<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Http\Requests\Api\V1\CustomerAppContentIndexRequest;
use Modules\Saas\Http\Requests\Api\V1\CustomerAppContentStoreRequest;
use Modules\Saas\Http\Requests\Api\V1\CustomerAppContentUpdateRequest;
use Modules\Saas\Http\Requests\Api\V1\CustomerAppMediaUploadRequest;
use Modules\Saas\Http\Requests\Api\V1\CustomerAppSettingsUpdateRequest;
use Modules\Saas\Models\CustomerAppContentItem;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\CustomerApp\CustomerAppContentService;
use Modules\Saas\Traits\ResolvesCurrentTenant;
use Modules\Support\ApiResponse;
use Modules\Notification\Services\Channels\EmailChannel;

class CustomerAppControlCenterController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(private readonly CustomerAppContentService $content) {}

    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->content->overview($this->currentTenant($request)));
    }

    public function content(CustomerAppContentIndexRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);

        return ApiResponse::success($this->content->presentPage($this->content->paginate($tenant, $request->filters())));
    }

    public function storeContent(CustomerAppContentStoreRequest $request): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);
            $item = $this->content->create($tenant, $request->payload(), $request->user());

            return ApiResponse::created(['item' => $this->content->presentContent($item)], 'customer app content');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_CONTENT']);
        }
    }

    public function updateContent(CustomerAppContentUpdateRequest $request, CustomerAppContentItem $item): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);
            $item = $this->content->update($tenant, $item, $request->payload(), $request->user());

            return ApiResponse::updated(['item' => $this->content->presentContent($item)], 'customer app content');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_CONTENT']);
        }
    }

    public function publishContent(Request $request, CustomerAppContentItem $item): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $item = $this->content->publish($tenant, $item, $request->user());

        return ApiResponse::success(['item' => $this->content->presentContent($item)], 'Customer app content published.');
    }

    public function unpublishContent(Request $request, CustomerAppContentItem $item): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $item = $this->content->unpublish($tenant, $item, $request->user());

        return ApiResponse::success(['item' => $this->content->presentContent($item)], 'Customer app content unpublished.');
    }

    public function destroyContent(Request $request, CustomerAppContentItem $item): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $this->content->delete($tenant, $item, $request->user());

        return ApiResponse::destroyed(true, 'customer app content');
    }

    public function publishAll(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);

        return ApiResponse::success($this->content->publishAll($tenant, $request->user()), 'Customer app changes published.');
    }

    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success(['settings' => $this->content->presentSettings($this->content->settings($this->currentTenant($request)))]);
    }

    public function updateSettings(CustomerAppSettingsUpdateRequest $request): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);
            $settings = $this->content->updateSettings($tenant, $request->payload(), $request->user());

            return ApiResponse::updated(['settings' => $this->content->presentSettings($settings)], 'customer app settings');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_SETTINGS']);
        }
    }

    public function preview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->content->preview($this->currentTenant($request)));
    }

    public function testEmail(Request $request, EmailChannel $email): JsonResponse
    {
        return $this->sendTestEmail($request, $this->currentTenant($request), $email);
    }

    public function uploadMedia(CustomerAppMediaUploadRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success([
                'media' => $this->content->uploadMedia($this->currentTenant($request), $request->mediaFile(), $request->user()),
            ], 'Media uploaded.');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_MEDIA']);
        }
    }

    public function saasOverview(Request $request, Tenant $tenant): JsonResponse
    {
        return ApiResponse::success($this->content->overview($tenant));
    }

    public function saasContent(CustomerAppContentIndexRequest $request, Tenant $tenant): JsonResponse
    {
        return ApiResponse::success($this->content->presentPage($this->content->paginate($tenant, $request->filters())));
    }

    public function saasSettings(Request $request, Tenant $tenant): JsonResponse
    {
        return ApiResponse::success(['settings' => $this->content->presentSettings($this->content->settings($tenant))]);
    }

    public function saasPreview(Request $request, Tenant $tenant): JsonResponse
    {
        return ApiResponse::success($this->content->preview($tenant));
    }

    public function saasTestEmail(Request $request, Tenant $tenant, EmailChannel $email): JsonResponse
    {
        return $this->sendTestEmail($request, $tenant, $email);
    }

    public function saasStoreContent(CustomerAppContentStoreRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $item = $this->content->create($tenant, $request->payload(), $request->user());

            return ApiResponse::created(['item' => $this->content->presentContent($item)], 'customer app content');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_CONTENT']);
        }
    }

    public function saasUpdateContent(CustomerAppContentUpdateRequest $request, Tenant $tenant, CustomerAppContentItem $item): JsonResponse
    {
        try {
            $item = $this->content->update($tenant, $item, $request->payload(), $request->user());

            return ApiResponse::updated(['item' => $this->content->presentContent($item)], 'customer app content');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_CONTENT']);
        }
    }

    public function saasPublishContent(Request $request, Tenant $tenant, CustomerAppContentItem $item): JsonResponse
    {
        return ApiResponse::success([
            'item' => $this->content->presentContent($this->content->publish($tenant, $item, $request->user())),
        ], 'Customer app content published.');
    }

    public function saasUnpublishContent(Request $request, Tenant $tenant, CustomerAppContentItem $item): JsonResponse
    {
        return ApiResponse::success([
            'item' => $this->content->presentContent($this->content->unpublish($tenant, $item, $request->user())),
        ], 'Customer app content unpublished.');
    }

    public function saasDestroyContent(Request $request, Tenant $tenant, CustomerAppContentItem $item): JsonResponse
    {
        $this->content->delete($tenant, $item, $request->user());

        return ApiResponse::destroyed(true, 'customer app content');
    }

    public function saasPublishAll(Request $request, Tenant $tenant): JsonResponse
    {
        return ApiResponse::success($this->content->publishAll($tenant, $request->user()), 'Customer app changes published.');
    }

    public function saasUpdateSettings(CustomerAppSettingsUpdateRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            return ApiResponse::updated([
                'settings' => $this->content->presentSettings($this->content->updateSettings($tenant, $request->payload(), $request->user())),
            ], 'customer app settings');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_SETTINGS']);
        }
    }

    public function saasUploadMedia(CustomerAppMediaUploadRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            return ApiResponse::success([
                'media' => $this->content->uploadMedia($tenant, $request->mediaFile(), $request->user()),
            ], 'Media uploaded.');
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::errors(null, $exception->getMessage(), 422, ['code' => 'INVALID_CUSTOMER_APP_MEDIA']);
        }
    }

    private function sendTestEmail(Request $request, Tenant $tenant, EmailChannel $email): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'email:rfc', 'max:160'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $brand = $this->content->preview($tenant)['runtime']['brand'] ?? [];

        try {
            $email->send($data['recipient'], [
                'subject' => $data['subject'],
                'body' => $data['body'],
                'restaurant_name' => $brand['name'] ?? $tenant->name,
                'logo_url' => $brand['logo_url'] ?? null,
            ]);

            return ApiResponse::success(['recipient' => $data['recipient']], 'Test email sent successfully.');
        } catch (\Throwable $exception) {
            report($exception);

            return ApiResponse::errors(
                ['code' => 'EMAIL_DELIVERY_FAILED'],
                'The mail server could not deliver the test email. Check the SMTP configuration and try again.',
                503,
            );
        }
    }
}
