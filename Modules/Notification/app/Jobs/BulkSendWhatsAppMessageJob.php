<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class BulkSendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public readonly ?int $tenantId;
    public readonly ?int $branchId;

    public const DYNAMIC_PARAMETERS = [
        'customer_name',
        'customer_phone',
        'last_order_date',
        'order_count',
        'total_spend',
    ];

    private const CUSTOMER_AUDIENCES = [
        'customers',
        'inactive_customers',
        'recent_customers',
        'high_value_customers',
        'birthday_customers',
        'anniversary_customers',
    ];

    public function __construct(
        public readonly string $audience,
        public readonly string $template,
        public readonly array $parameters = [],
        public readonly ?array $roleNames = null,
        public readonly ?array $recipientIds = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $scheduledAt = null,
        ?int $tenantId = null,
        ?int $branchId = null,
    ) {
        $user = auth()->user();
        $this->tenantId = $tenantId ?? $user?->tenantId();
        $this->branchId = $branchId ?? $user?->branchId();
        $this->onQueue('whatsapp');
    }

    public function handle(): void
    {
        if ($this->tenantId === null) {
            throw new \LogicException('A tenant-bound audience is required for restaurant communication campaigns.');
        }

        $this->query()->select('id', 'name', 'phone', 'phone_country_iso_code')->chunkById(50, function ($users, $chunk) {
            foreach ($users as $user) {
                if (blank($user->phone)) {
                    continue;
                }

                // Add delay between messages to prevent rate limiting (1 second per message)
                $delay = $chunk * 50 + collect($users)->search($user) + 1;

                SendWhatsAppMessageJob::dispatch(
                    str_replace(' ', '', $user->phone),
                    $this->template,
                    $this->parametersFor($user),
                    $this->metadataFor($user),
                )->delay(now()->addSeconds($delay));
            }
        });
    }

    public function recipientCount(): int
    {
        return (clone $this->query())->count();
    }

    public static function audienceKeys(): array
    {
        return [
            'customers',
            'users',
            'roles',
            'inactive_customers',
            'recent_customers',
            'high_value_customers',
            'birthday_customers',
            'anniversary_customers',
        ];
    }

    public static function audienceOptions(): array
    {
        return collect(self::audienceKeys())
            ->map(fn(string $audience) => [
                'id' => $audience,
                'name' => __("notification::notifications.audiences.$audience"),
            ])
            ->all();
    }

    private function query(): Builder
    {
        return User::query()
            ->withoutGlobalActive()
            ->when(
                $this->tenantId !== null,
                fn (Builder $query) => $query->where('tenant_id', $this->tenantId),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->when(
                $this->branchId !== null,
                fn (Builder $query) => $query->where('branch_id', $this->branchId),
            )
            ->whereNotNull('phone')
            ->when(
                in_array($this->audience, self::CUSTOMER_AUDIENCES, true),
                function (Builder $query) {
                    $query->role(DefaultRole::Customer);

                    // Business-initiated campaigns are opt-in only. Schema
                    // guards keep rolling deployments safe while migrations
                    // are applied across workers.
                    if (Schema::hasColumn('users', 'whatsapp_marketing_consent')) {
                        $query->where('whatsapp_marketing_consent', true);
                    }
                    if (Schema::hasColumn('users', 'phone_verified_at')) {
                        $query->whereNotNull('phone_verified_at');
                    }
                    if (Schema::hasColumn('users', 'whatsapp_opted_out_at')) {
                        $query->whereNull('whatsapp_opted_out_at');
                    }

                    return $query;
                }
            )
            ->when(
                $this->audience === 'inactive_customers',
                fn(Builder $query) => $this->inactiveCustomers($query)
            )
            ->when(
                $this->audience === 'recent_customers',
                fn(Builder $query) => $this->recentCustomers($query)
            )
            ->when(
                $this->audience === 'high_value_customers',
                fn(Builder $query) => $this->highValueCustomers($query)
            )
            ->when(
                $this->audience === 'birthday_customers',
                fn(Builder $query) => $this->birthdayCustomers($query)
            )
            ->when(
                $this->audience === 'anniversary_customers',
                fn(Builder $query) => $this->anniversaryCustomers($query)
            )
            ->when(
                $this->audience === 'users',
                fn(Builder $query) => $query->role(roles: DefaultRole::Customer, without: true)
            )
            ->when(
                $this->audience === 'roles' && filled($this->roleNames),
                fn(Builder $query) => $query->role($this->roleNames)
            )
            ->when(
                filled($this->recipientIds),
                fn(Builder $query) => $query->whereIn('id', $this->recipientIds)
            );
    }

    private function inactiveCustomers(Builder $query): Builder
    {
        $days = max(1, (int) (setting('crm_inactive_customer_days') ?: 30));

        return $query->whereNotIn('id', $this->orders()
            ->select('customer_id')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', now()->subDays($days)));
    }

    private function recentCustomers(Builder $query): Builder
    {
        $days = max(1, (int) (setting('crm_recent_customer_days') ?: 7));

        return $query->whereIn('id', $this->orders()
            ->select('customer_id')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', now()->subDays($days)));
    }

    private function highValueCustomers(Builder $query): Builder
    {
        $minimumSpend = max(0, (float) (setting('crm_high_value_customer_min_spend') ?: 500));

        return $query->whereIn('id', $this->orders()
            ->select('customer_id')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('SUM(total * COALESCE(currency_rate, 1)) >= ?', [$minimumSpend]));
    }

    private function birthdayCustomers(Builder $query): Builder
    {
        if (!Schema::hasColumn('users', 'date_of_birth')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereMonth('date_of_birth', now()->month)
            ->whereDay('date_of_birth', now()->day);
    }

    private function anniversaryCustomers(Builder $query): Builder
    {
        if (!Schema::hasColumn('users', 'anniversary_date')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereMonth('anniversary_date', now()->month)
            ->whereDay('anniversary_date', now()->day);
    }

    private function parametersFor(User $user): array
    {
        $summary = $this->orders()
            ->where('customer_id', $user->id)
            ->selectRaw('COUNT(*) as order_count, SUM(total * COALESCE(currency_rate, 1)) as total_spend, MAX(created_at) as last_order_date')
            ->first();

        $dynamic = [
            'customer_name' => $user->name,
            'customer_phone' => str_replace(' ', '', (string) $user->phone),
            'last_order_date' => $summary?->last_order_date ? dateFormat(\Carbon\Carbon::parse($summary->last_order_date)) : '',
            'order_count' => (string) ((int) ($summary?->order_count ?? 0)),
            'total_spend' => number_format((float) ($summary?->total_spend ?? 0), 2),
        ];

        return array_replace($this->parameters, $dynamic);
    }

    private function metadataFor(User $user): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'branch_id' => $this->branchId,
            'campaign_id' => $this->campaignId,
            'audience' => $this->audience,
            'recipient_id' => $user->id,
            'recipient_name' => $user->name,
            'role_names' => $this->roleNames,
            'scheduled_at' => $this->scheduledAt,
        ];
    }

    private function orders(): Builder
    {
        return Order::query()
            ->when(
                $this->branchId !== null,
                fn (Builder $query) => $query->where('branch_id', $this->branchId),
                fn (Builder $query) => $query->whereHas(
                    'branch',
                    fn (Builder $branch) => $branch->where('tenant_id', $this->tenantId),
                ),
            );
    }
}
