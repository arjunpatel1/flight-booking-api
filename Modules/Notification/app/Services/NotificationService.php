<?php

namespace Modules\Notification\Services;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Events\NotificationCreated;
use Modules\Notification\Models\Notification;
use Modules\User\Models\User;

class NotificationService implements NotificationServiceInterface
{
    public function create(array $data, ?User $targetUser = null): Notification
    {
        $severity = NotificationSeverity::tryFrom($data['severity'] ?? NotificationSeverity::Info->value)
            ?: NotificationSeverity::Info;

        $notification = Notification::create([
            'target_user_id' => $targetUser?->id ?? $data['target_user_id'] ?? null,
            'title' => $data['title'],
            'message' => $data['message'] ?? null,
            'type' => $data['type'] ?? 'system',
            'severity' => $severity,
            'icon' => $data['icon'] ?? $severity->icon(),
            'color' => $data['color'] ?? $severity->color(),
            'action_url' => $data['action_url'] ?? null,
            'payload' => $data['payload'] ?? null,
        ]);

        if ($notification->target_user_id) {
            event(new NotificationCreated($notification));
        }

        return $notification;
    }

    public function getForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        return Notification::query()
            ->where(fn($query) => $query
                ->where('target_user_id', $user->id)
                ->orWhereNull('target_user_id'))
            ->whereNull('dismissed_at')
            ->whereNull('archived_at')
            ->filters($filters)
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->where(fn($query) => $query
                ->where('target_user_id', $user->id)
                ->orWhereNull('target_user_id'))
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->whereNull('archived_at')
            ->count();
    }

    public function markAsRead(User $user, int|string $id): void
    {
        Notification::query()
            ->where(fn($query) => $query
                ->where('target_user_id', $user->id)
                ->orWhereNull('target_user_id'))
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllAsRead(User $user): void
    {
        Notification::query()
            ->where(fn($query) => $query
                ->where('target_user_id', $user->id)
                ->orWhereNull('target_user_id'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function dismiss(User $user, int|string $id): void
    {
        Notification::query()
            ->where(fn($query) => $query
                ->where('target_user_id', $user->id)
                ->orWhereNull('target_user_id'))
            ->where('id', $id)
            ->update([
                'read_at' => now(),
                'dismissed_at' => now(),
            ]);
    }

    public function clear(User $user): void
    {
        Notification::query()
            ->where('target_user_id', $user->id)
            ->delete();
    }
}
