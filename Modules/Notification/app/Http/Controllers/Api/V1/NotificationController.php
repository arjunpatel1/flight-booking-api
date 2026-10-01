<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Notification\Transformers\Api\V1\NotificationResource;
use Modules\Support\ApiResponse;
use Modules\Support\GlobalStructureFilters;
use Modules\Branch\Models\Branch;
use Modules\User\Models\Role;
use Modules\User\Models\User;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->getForUser($request->user(), $request->get('filters', [])),
            resource: NotificationResource::class,
            filters: $request->get('with_filters') ? $this->filters() : null,
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            "unread_count" => $this->service->unreadCount($request->user()),
        ]);
    }

    public function communicationMeta(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user?->assignedToBranch() && !$user->isSuperAdmin() ? $user->branch_id : null;

        return ApiResponse::success([
            'users' => User::query()
                ->withoutGlobalActive()
                ->select(['id', 'name', 'branch_id'])
                ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
                ->with(['branch:id,name', 'roles:id,name,display_name'])
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->map(fn(User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'branch' => $user->branch?->name,
                    'roles' => $user->roles->map(fn($role) => [
                        'id' => $role->id,
                        'name' => $role->display_name,
                    ])->values(),
                ]),
            'roles' => Role::list(withCustomer: false),
            'branches' => Branch::query()
                ->withoutGlobalActive()
                ->select(['id', 'name'])
                ->when($branchId, fn($query) => $query->whereKey($branchId))
                ->orderBy('name')
                ->get()
                ->map(fn(Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ]),
            'severities' => $this->enumOptions(NotificationSeverity::values()),
        ]);
    }

    public function internalMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['all', 'users', 'roles', 'branches'])],
            'target_user_ids' => ['required_if:target_type,users', 'array'],
            'target_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'target_role_ids' => ['required_if:target_type,roles', 'array'],
            'target_role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'target_branch_ids' => ['required_if:target_type,branches', 'array'],
            'target_branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'severity' => ['nullable', Rule::enum(NotificationSeverity::class)],
            'action_url' => ['nullable', 'string', 'max:500'],
        ]);

        $basePayload = [
            'title' => $data['title'],
            'message' => $data['message'],
            'type' => 'communication',
            'severity' => $data['severity'] ?? NotificationSeverity::Info->value,
            'icon' => 'tabler-message',
            'action_url' => $data['action_url'] ?? null,
            'payload' => [
                'sent_by' => $request->user()?->id,
                'target_type' => $data['target_type'],
            ],
        ];

        $created = 0;
        $sender = $request->user();
        $branchId = $sender?->assignedToBranch() && !$sender->isSuperAdmin() ? $sender->branch_id : null;

        if ($data['target_type'] === 'all') {
            if ($branchId) {
                User::query()
                    ->where('branch_id', $branchId)
                    ->get()
                    ->each(function (User $user) use ($basePayload, &$created) {
                        $this->service->create($basePayload, $user);
                        $created++;
                    });
            } else {
                $this->service->create($basePayload);
                $created = 1;
            }
        }

        if ($data['target_type'] === 'users') {
            User::query()
                ->whereIn('id', $data['target_user_ids'] ?? [])
                ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
                ->get()
                ->each(function (User $user) use ($basePayload, &$created) {
                    $this->service->create($basePayload, $user);
                    $created++;
                });
        }

        if ($data['target_type'] === 'roles') {
            $roleNames = Role::query()
                ->whereIn('id', $data['target_role_ids'] ?? [])
                ->pluck('name')
                ->all();

            User::query()
                ->role($roleNames)
                ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
                ->get()
                ->each(function (User $user) use ($basePayload, &$created) {
                    $this->service->create($basePayload, $user);
                    $created++;
                });
        }

        if ($data['target_type'] === 'branches') {
            User::query()
                ->whereIn('branch_id', $data['target_branch_ids'] ?? [])
                ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
                ->get()
                ->each(function (User $user) use ($basePayload, &$created) {
                    $this->service->create($basePayload, $user);
                    $created++;
                });
        }

        return ApiResponse::success([
            'created' => $created,
        ]);
    }

    public function read(Request $request, int|string $id): JsonResponse
    {
        $this->service->markAsRead($request->user(), $id);

        return ApiResponse::success(["success" => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->service->markAllAsRead($request->user());

        return ApiResponse::success(["success" => true]);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->service->clear($request->user());

        return ApiResponse::success(["success" => true]);
    }

    public function dismiss(Request $request, int|string $id): JsonResponse
    {
        $this->service->dismiss($request->user(), $id);

        return ApiResponse::success(["success" => true]);
    }

    public function channels(): JsonResponse
    {
        $whatsappProvider = setting('whatsapp_provider') ?: 'msg91';
        $whatsappConfigured = match ($whatsappProvider) {
            'nexmsg' => filled(setting('whatsapp_nexmsg_account_id')) && filled(setting('whatsapp_nexmsg_auth_key')),
            'msg91' => filled(setting('whatsapp_msg91_auth_key')) && filled(setting('whatsapp_msg91_integrated_number') ?: setting('whatsapp_msg91_sender_id')),
            'meta' => filled(setting('whatsapp_meta_access_token')) && filled(setting('whatsapp_meta_phone_number_id')),
            'twilio' => filled(setting('whatsapp_twilio_sid')) && filled(setting('whatsapp_twilio_auth_token')) && filled(setting('whatsapp_twilio_from')),
            default => false,
        };
        $firebaseConfigured = (bool) setting('firebase_enabled', filled(config('services.firebase.credentials')))
            && (filled(setting('firebase_service_account_json')) || filled(config('services.firebase.credentials')));

        return ApiResponse::success([
            'email' => [
                'enabled' => config('mail.default') !== null && config('mail.default') !== 'log',
                'driver' => config('mail.default'),
                'from_address' => config('mail.from.address'),
            ],
            'sms' => [
                'enabled' => (bool) setting('notifications_sms_enabled', false),
                'provider' => setting('sms_provider') ?: config('services.sms.provider'),
            ],
            'whatsapp' => [
                'enabled' => (bool) setting('whatsapp_enabled', false) && $whatsappConfigured,
                'provider' => $whatsappProvider,
            ],
            'push' => [
                'enabled' => (bool) setting('notifications_push_enabled', false) && $firebaseConfigured,
                'provider' => config('services.push.provider', 'firebase'),
            ],
            'broadcast' => [
                'enabled' => config('broadcasting.default') !== null,
                'driver' => config('broadcasting.default'),
            ],
        ]);
    }

    private function filters(): array
    {
        return [
            [
                "key" => "type",
                "label" => __("notification::notifications.filters.type"),
                "type" => "select",
                "options" => $this->options(['order', 'aggregator', 'whatsapp', 'communication', 'system']),
            ],
            [
                "key" => "severity",
                "label" => __("notification::notifications.filters.severity"),
                "type" => "select",
                "options" => $this->enumOptions(NotificationSeverity::values()),
            ],
            [
                "key" => "read_status",
                "label" => __("notification::notifications.filters.read_status"),
                "type" => "select",
                "options" => $this->options(['read', 'unread']),
            ],
            [
                "key" => "state",
                "label" => __("notification::notifications.filters.state"),
                "type" => "select",
                "options" => $this->options(['unread', 'read', 'dismissed', 'archived']),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    private function enumOptions(array $values): array
    {
        return array_map(fn(string $value) => [
            "id" => $value,
            "name" => __("notification::notifications.filters.options.{$value}"),
        ], $values);
    }

    private function options(array $values): array
    {
        return $this->enumOptions($values);
    }
}
