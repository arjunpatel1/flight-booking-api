<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Notification\Models\Notification;
use Modules\User\Models\User;

interface NotificationServiceInterface
{
    public function create(array $data, ?User $targetUser = null): Notification;

    public function getForUser(User $user, array $filters = []): LengthAwarePaginator;

    public function unreadCount(User $user): int;

    public function markAsRead(User $user, int|string $id): void;

    public function markAllAsRead(User $user): void;

    public function dismiss(User $user, int|string $id): void;

    public function clear(User $user): void;
}
