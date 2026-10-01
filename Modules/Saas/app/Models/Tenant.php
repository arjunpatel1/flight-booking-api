<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

class Tenant extends Model
{
    use HasActivityLog,
        HasActiveStatus,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'domain',
        'contact_name',
        'contact_email',
        'contact_phone',
        'settings',
        self::ACTIVE_COLUMN_NAME,
    ];

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $actor = auth()->user();
        $isPlatformActor = $actor?->isSuperAdmin()
            && ! $actor->assignedToTenant()
            && ! $actor->assignedToBranch();

        if ($isPlatformActor) {
            // Suspension is an access state, not deletion. Platform operators
            // must still be able to open and recover the restaurant record.
            $query = $this->newQuery()->withoutGlobalActive();
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Restrict the tenant directory to the caller's own restaurant.
     *
     * Without this, any authenticated tenant user could enumerate every other
     * restaurant on the platform — names, domains, contact names, emails and
     * phone numbers. The control-plane routes are permission-guarded, but the
     * model itself was open, so a single unguarded query would have exposed the
     * customer list.
     *
     * Exempt, matching the other isolation traits: unauthenticated callers
     * (queue jobs, public client-config endpoints), platform super admins, and
     * users with no tenant of their own. Deliberate control-plane and
     * provisioning lookups already use `withoutGlobalScopes()`.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->uuid ??= (string) Str::uuid();
        });

        static::addGlobalScope('tenant_self', function (Builder $builder) {
            if (! auth()->guard()->hasUser()) {
                return;
            }

            $user = auth()->user();

            if (! $user || $user->isSuperAdmin() || ! $user->assignedToTenant()) {
                return;
            }

            $builder->whereKey($user->tenantId());
        });
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function customerAppRegistrations(): HasMany
    {
        return $this->hasMany(CustomerAppRegistration::class);
    }

    public function customerAppBuilds(): HasMany
    {
        return $this->hasMany(CustomerAppBuild::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function provisioningRuns(): HasMany
    {
        return $this->hasMany(SaasProvisioningRun::class);
    }

    public function latestProvisioningRun(): HasOne
    {
        return $this->hasOne(SaasProvisioningRun::class)->latestOfMany();
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class)
            ->whereIn('status', ['trial', 'active'])
            ->latestOfMany();
    }

    public function featureLimits(): HasMany
    {
        return $this->hasMany(TenantFeatureLimit::class);
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'is_active', 'from', 'to', 'needs_attention', 'onboarding_status', 'subscription_status', 'payment_status', 'health_status', 'plan_id', 'city', 'renewal_status', 'lifecycle_stage'];
    }

    protected function getSortableAttributes(): array
    {
        return ['name', 'slug', 'domain', 'is_active'];
    }

    /**
     * Registry search. Owner name and phone are included because operators
     * routinely search by the person who called, not the restaurant's trading
     * name — the two are often different.
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('name', "%{$value}%")
            ->orWhereLike('legal_name', "%{$value}%")
            ->orWhereLike('slug', "%{$value}%")
            ->orWhereLike('domain', "%{$value}%")
            ->orWhereLike('contact_name', "%{$value}%")
            ->orWhereLike('contact_email', "%{$value}%")
            ->orWhereLike('contact_phone', "%{$value}%");
    }

    public function scopeOnboardingStatus(Builder $query, string $status): void
    {
        if (! Schema::hasTable('saas_provisioning_runs')) {
            if ($status === 'pending') $query->whereRaw('1 = 1');
            return;
        }

        $coreSetupComplete = function (Builder $tenant): void {
            $tenant->whereNotNull('domain')
                ->where('domain', '<>', '')
                ->whereHas('branches')
                ->whereHas('users.roles', fn (Builder $role) => $role
                    ->whereIn('name', ['enterprise_admin', 'admin_branch']))
                ->whereHas('subscriptions', fn (Builder $subscription) => $subscription
                    ->whereIn('status', ['trial', 'active']));
        };

        $operationalSetupComplete = function (Builder $tenant): void {
            $tenant->has('users', '>=', 2);

            foreach (['pos_terminal_devices', 'print_agents', 'orders'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) continue;

                $tenant->whereExists(fn ($record) => $record
                    ->selectRaw('1')
                    ->from($table)
                    ->join('branches', 'branches.id', '=', "{$table}.branch_id")
                    ->whereColumn('branches.tenant_id', 'tenants.id')
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($query) => $query->whereNull("{$table}.deleted_at")));
            }
        };

        if ($status === 'pending') {
            $query->where(function (Builder $inSetup) {
                $inSetup->whereDoesntHave('provisioningRuns')
                    ->orWhereHas('latestProvisioningRun', fn (Builder $run) => $run
                        ->where(function (Builder $incomplete) {
                            $incomplete->whereNull('progress')->orWhere('progress', '<', 100);
                        })
                        ->whereNotIn('status', ['cancelled']))
                    ->orWhereNull('domain')
                    ->orWhere('domain', '')
                    ->orWhereDoesntHave('branches')
                    ->orWhereDoesntHave('users.roles', fn (Builder $role) => $role
                        ->whereIn('name', ['enterprise_admin', 'admin_branch']))
                    ->orWhereDoesntHave('subscriptions', fn (Builder $subscription) => $subscription
                        ->whereIn('status', ['trial', 'active']))
                    ->orWhereHas('users', fn (Builder $users) => $users, '<', 2);

                foreach (['pos_terminal_devices', 'print_agents', 'orders'] as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) continue;

                    $inSetup->orWhereNotExists(fn ($record) => $record
                        ->selectRaw('1')
                        ->from($table)
                        ->join('branches', 'branches.id', '=', "{$table}.branch_id")
                        ->whereColumn('branches.tenant_id', 'tenants.id')
                        ->when(Schema::hasColumn($table, 'deleted_at'), fn ($subquery) => $subquery->whereNull("{$table}.deleted_at")));
                }
            });
            return;
        }

        if ($status === 'completed') {
            $query->where(function (Builder $tenant) use ($coreSetupComplete, $operationalSetupComplete) {
                $coreSetupComplete($tenant);
                $operationalSetupComplete($tenant);
            })->whereHas('latestProvisioningRun', fn (Builder $run) => $run
                    ->where('status', 'completed')
                    ->where('progress', '>=', 100));
            return;
        }

        $query->whereHas('latestProvisioningRun', fn (Builder $run) => $run->where('status', $status));
    }

    public function scopeSubscriptionStatus(Builder $query, string $status): void
    {
        if (Schema::hasTable('tenant_subscriptions')) {
            $query->whereHas('subscriptions', fn (Builder $subscription) => $subscription->where('status', $status));
        }
    }

    public function scopePlanId(Builder $query, int|string $planId): void
    {
        if (Schema::hasTable('tenant_subscriptions')) {
            $query->whereHas('subscriptions', fn (Builder $subscription) => $subscription
                ->where('subscription_plan_id', $planId)
                ->whereIn('status', ['trial', 'active', 'grace', 'past_due']));
        }
    }

    public function scopeCity(Builder $query, string $city): void
    {
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'city')) {
            $query->whereHas('branches', fn (Builder $branch) => $branch->where('city', $city));
        }
    }

    public function scopeRenewalStatus(Builder $query, string $status): void
    {
        if (! Schema::hasTable('tenant_subscriptions')) return;

        $days = $status === 'due_7' ? 7 : 30;
        $query->whereHas('subscriptions', fn (Builder $subscription) => $subscription
            ->whereIn('status', ['trial', 'active', 'grace'])
            ->whereBetween('ends_at', [now(), now()->addDays($days)]));
    }

    public function scopePaymentStatus(Builder $query, string $status): void
    {
        if (! Schema::hasTable('saas_billing_invoices')) return;

        $statuses = $status === 'due' ? ['issued', 'pending', 'payment_pending', 'overdue', 'failed'] : [$status];
        $query->whereExists(function ($invoice) use ($statuses) {
            $invoice->selectRaw('1')
                ->from('saas_billing_invoices')
                ->whereColumn('saas_billing_invoices.tenant_id', 'tenants.id')
                ->whereNull('saas_billing_invoices.deleted_at')
                ->whereIn('saas_billing_invoices.status', $statuses);
        });
    }

    public function scopeHealthStatus(Builder $query, string $status): void
    {
        if ($status !== 'unhealthy') return;
        $this->scopeNeedsAttention($query, true);
    }

    /**
     * Filter by commercial lifecycle stage.
     *
     * Guarded on the column existing so the registry keeps working on an
     * environment where the lifecycle migration has not been applied yet.
     */
    public function scopeLifecycleStage(Builder $query, string $stage): void
    {
        if (! Schema::hasColumn($this->getTable(), 'lifecycle_stage')) {
            return;
        }

        $query->where('lifecycle_stage', $stage);
    }

    public function scopeNeedsAttention(Builder $query, mixed $enabled): void
    {
        if (! filter_var($enabled, FILTER_VALIDATE_BOOL)) return;

        $query->where(function (Builder $attention) {
            $attention->where('is_active', false)
                ->orWhereNull('domain');

            if (Schema::hasTable('tenant_subscriptions')) {
                $attention->orWhereDoesntHave('subscriptions', fn (Builder $subscription) => $subscription->whereIn('status', ['trial', 'active']));
            }

            if (Schema::hasTable('saas_provisioning_runs')) {
                $attention->orWhereDoesntHave('provisioningRuns')
                    ->orWhereHas('latestProvisioningRun', fn (Builder $run) => $run->whereIn('status', ['failed', 'partially_completed']));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
