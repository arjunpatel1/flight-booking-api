<?php

namespace Modules\Saas\Services\Provisioning;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Saas\Jobs\RunTenantServerAutomationJob;
use Modules\Saas\Jobs\TriggerWaiterAppBuildJob;
use Modules\Saas\Models\SaasDeliveryJob;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Support\ProvisioningWorkflow;
use Modules\Saas\Support\TenantContext;
use Modules\SeatingPlan\Enums\TableShape;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;
use Modules\Setting\Models\Setting;
use Modules\Tax\Enums\GstType;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;
use Modules\User\Enums\DefaultRole;
use Modules\User\Facades\Permission;
use Modules\User\Models\Role;
use Modules\User\Models\User;

class SaasProvisioningService
{
    public function complete(Tenant $tenant, array $data): array
    {
        abort_if($tenant->latestProvisioningRun()->whereIn('status', ['pending', 'processing'])->exists(), 422, 'Restaurant setup is already running.');

        $password = $data['password'] ?? Str::password(16);
        $data = [
            ...$data,
            'name' => $data['name'] ?? $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $data['domain'] ?? $tenant->domain,
            'email' => $data['email'] ?? $tenant->contact_email,
            'phone' => $data['phone'] ?? $tenant->contact_phone,
        ];

        $result = DB::transaction(function () use ($tenant, $data, $password) {
            $settings = $tenant->settings ?: [];
            $settings['theme'] = [
                ...($settings['theme'] ?? []),
                'primary' => $data['primary_color'] ?? data_get($settings, 'theme.primary', '#ff6b00'),
                'secondary' => $data['secondary_color'] ?? data_get($settings, 'theme.secondary', '#0f172a'),
            ];
            $tenant->forceFill([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? $tenant->legal_name ?? $data['name'],
                'domain' => $data['domain'],
                'contact_name' => $data['admin_name'] ?? $tenant->contact_name,
                'contact_email' => $data['email'],
                'contact_phone' => $data['phone'],
                'settings' => $settings,
            ])->save();

            app(TenantContext::class)->set($tenant);
            $branch = $this->branch($tenant, $data);
            $this->ensureDefaultRolesExist();
            $plan = $this->plan($data['plan'] ?? 'starter');
            $admin = $this->adminUser($tenant, $branch, $plan, $data, $password);
            $staff = $this->initialStaffUser($tenant, $branch, $data);
            $subscription = $this->subscription($tenant, $plan, $data);
            $run = $this->provisioningRun($tenant, $branch);
            $this->settings($tenant, $branch, $data);

            return [
                'tenant' => $this->freshTenantWorkspace($tenant),
                'branch' => $branch->fresh(), 'admin' => $admin->fresh(),
                'staff' => $staff?->fresh(),
                'subscription' => $subscription->fresh('plan'), 'provisioning_run' => $run->fresh(),
                'delivery_jobs' => [], 'password' => $password, 'urls' => $this->urls($tenant, $branch),
            ];
        });

        $this->dispatchPostProvisioning($result['provisioning_run']);

        return $result;
    }

    public function provision(array $data): array
    {
        $password = $data['password'] ?? Str::password(16);
        $slug = Str::slug($data['slug'] ?? $data['name']);

        $result = DB::transaction(function () use ($data, $slug, $password) {
            $tenant = $this->tenant($data, $slug);
            app(TenantContext::class)->set($tenant);

            $branch = $this->branch($tenant, $data);
            $this->ensureDefaultRolesExist();
            $plan = $this->plan($data['plan'] ?? 'starter');
            $admin = $this->adminUser($tenant, $branch, $plan, $data, $password);
            $staff = $this->initialStaffUser($tenant, $branch, $data);
            $subscription = $this->subscription($tenant, $plan, $data);
            $run = $this->provisioningRun($tenant, $branch);
            $deliveryJobs = $this->deliveryJobs($tenant, $run, $data);

            $this->settings($tenant, $branch, $data);

            return [
                'tenant' => $this->freshTenantWorkspace($tenant),
                'branch' => $branch->fresh(),
                'admin' => $admin->fresh(),
                'staff' => $staff?->fresh(),
                'subscription' => $subscription->fresh('plan'),
                'provisioning_run' => $run->fresh(),
                'delivery_jobs' => $deliveryJobs,
                'password' => $password,
                'urls' => $this->urls($tenant, $branch),
            ];
        });

        $this->dispatchPostProvisioning($result['provisioning_run']);
        $this->dispatchDeliveryJobs($result['delivery_jobs']);

        return $result;
    }

    public function storage(Tenant $tenant): array
    {
        $base = "tenants/{$tenant->id}";
        $directories = [
            'logos',
            'media',
            'uploads',
            'exports',
            'reports',
            'invoices',
            'printer',
            'qr',
            'backups',
        ];

        foreach ($directories as $directory) {
            Storage::disk('local')->makeDirectory("{$base}/{$directory}");
        }

        Storage::disk('local')->put(
            "{$base}/logos/placeholder.txt",
            "Upload tenant logo here. Tenant: {$tenant->slug}\n"
        );

        return array_map(fn (string $directory) => Storage::disk('local')->path("{$base}/{$directory}"), $directories);
    }

    private function tenant(array $data, string $slug): Tenant
    {
        $domain = $data['domain'] ?? $this->domain($slug);

        $tenant = Tenant::query()->withoutGlobalScopes()->withTrashed()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? $data['name'],
                'domain' => $domain,
                'contact_name' => $data['contact_name'] ?? $data['admin_name'] ?? "{$data['name']} Admin",
                'contact_email' => $data['email'],
                'contact_phone' => $data['phone'] ?? null,
                'settings' => [
                    'lifecycle_status' => 'active',
                    'cache_namespace' => 'tenant:pending:cache',
                    'queue_namespace' => 'tenant:pending:queue',
                    'broadcast_namespace' => 'tenant:pending:broadcast',
                    'theme' => [
                        'primary' => $data['primary_color'] ?? '#ff6b00',
                        'secondary' => $data['secondary_color'] ?? '#0f172a',
                    ],
                    'logo_url' => $data['logo_url'] ?? null,
                ],
                'is_active' => true,
            ]
        );

        if ($tenant->trashed()) {
            $tenant->restore();
        }

        $settings = $tenant->settings ?: [];
        $settings['cache_namespace'] = "tenant:{$tenant->id}:cache";
        $settings['queue_namespace'] = "tenant:{$tenant->id}:queue";
        $settings['broadcast_namespace'] = "tenant:{$tenant->id}:broadcast";
        $settings['reverb_channels'] = [
            "tenant.{$tenant->id}.orders",
            "tenant.{$tenant->id}.kitchen",
            "tenant.{$tenant->id}.waiter",
            "tenant.{$tenant->id}.notifications",
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        return $tenant;
    }

    private function freshTenantWorkspace(Tenant $tenant): Tenant
    {
        return $tenant
            ->fresh([
                'branches',
                'users.roles:id,name',
                'subscriptions.plan',
                'activeSubscription.plan',
            ])
            ->loadCount(['branches', 'users', 'subscriptions']);
    }

    private function branch(Tenant $tenant, array $data): Branch
    {
        $registration = 'TENANT-'.Str::upper($tenant->slug);
        $branch = Branch::query()->withoutGlobalScopes()->withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $query->where('registration_number', $registration)->orWhere('is_main', true))
            ->first() ?? Branch::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->first();
        $branch ??= new Branch;
        $branch->forceFill([
            'tenant_id' => $tenant->id,
            'registration_number' => $branch->registration_number ?: $registration,
            'name' => ['en' => $data['branch_name'] ?? $tenant->name],
            'legal_name' => $data['legal_name'] ?? $tenant->legal_name ?? $tenant->name,
            'country_code' => $data['country_code'] ?? Setting::get('default_country', 'IN'),
            'timezone' => $data['timezone'] ?? Setting::get('default_timezone', 'Asia/Kolkata'),
            'currency' => $data['currency'] ?? Setting::get('default_currency', 'INR'),
            'is_active' => true,
            'is_main' => true,
            'address_line1' => $data['address'] ?? $tenant->name,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'],
            'order_types' => OrderType::values(),
            'payment_methods' => PaymentMethod::values(),
        ])->save();
        if ($branch->trashed()) {
            $branch->restore();
        }

        return $branch;
    }

    private function ensureDefaultRolesExist(): void
    {
        $permissionNames = Permission::getPermissionNames();
        $rolesAreCurrent = collect([DefaultRole::EnterpriseAdmin, DefaultRole::AdminBranch])
            ->every(function (DefaultRole $defaultRole) use ($permissionNames): bool {
                $role = Role::query()->where('name', $defaultRole->value)
                    ->with('permissions:id,name')->first();
                $expected = collect(DefaultRole::expandPermissions(
                    $defaultRole->getPermissions(),
                    $permissionNames,
                ));

                return $role && $expected->diff($role->permissions->pluck('name'))->isEmpty();
            });

        if ($rolesAreCurrent) {
            return;
        }

        $startedAt = microtime(true);

        Artisan::call('permission:sync-permissions');
        Artisan::call('permission:sync-default-roles', ['--force' => true]);

        Log::info('Default roles were missing during SaaS provisioning and were synced once.', [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function adminUser(Tenant $tenant, Branch $branch, SubscriptionPlan $plan, array $data, string $password): User
    {
        $isBranchPlan = ($plan->access_scope ?? 'tenant') === 'branch';
        $user = User::query()->withoutGlobalScopes()->updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['admin_name'] ?? "{$tenant->name} Admin",
                'username' => $data['username'] ?? "{$tenant->slug}_admin",
                'tenant_id' => $tenant->id,
                'branch_id' => $isBranchPlan ? $branch->id : null,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($password),
                'is_active' => true,
            ]
        );

        $user->syncRoles([
            $isBranchPlan
                ? DefaultRole::AdminBranch->value
                : DefaultRole::EnterpriseAdmin->value,
        ]);

        return $user;
    }

    private function initialStaffUser(Tenant $tenant, Branch $branch, array $data): ?User
    {
        if (blank($data['staff_name'] ?? null) || blank($data['staff_email'] ?? null)) {
            return null;
        }

        $role = DefaultRole::tryFrom($data['staff_role'] ?? DefaultRole::Waiter->value);
        if (! $role || ! in_array($role, [DefaultRole::Manager, DefaultRole::Cashier, DefaultRole::Kitchen, DefaultRole::Waiter], true)) {
            $role = DefaultRole::Waiter;
        }

        $user = User::query()->withoutGlobalScopes()->updateOrCreate(
            ['email' => $data['staff_email']],
            [
                'name' => $data['staff_name'],
                'username' => $data['staff_username'] ?? Str::slug($tenant->slug.'-'.$data['staff_name'].'-'.$tenant->id, '_'),
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'password' => Hash::make($data['staff_password']),
                'is_active' => true,
            ]
        );
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function plan(string $code): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()->withoutGlobalScopes()->firstOrCreate(
            ['code' => $code],
            [
                'name' => Str::headline($code),
                'description' => 'Default SaaS starter plan.',
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'INR',
                'features' => config('saas.default_features', []),
                'limits' => ['branches' => null, 'users' => null, 'orders' => null],
                'is_active' => true,
            ]
        );

        // Keep only system-generated plans aligned with the minimum features
        // required to operate a restaurant. Custom commercial plans remain
        // untouched and continue to be managed from Billing → Plans.
        if ($plan->description === 'Default SaaS starter plan.') {
            $features = collect($plan->features ?: [])
                ->merge(config('saas.default_features', []))
                ->unique()
                ->values()
                ->all();
            if ($features !== ($plan->features ?: [])) {
                $plan->forceFill(['features' => $features])->save();
            }
        }

        return $plan;
    }

    private function subscription(Tenant $tenant, SubscriptionPlan $plan, array $data): TenantSubscription
    {
        $subscription = TenantSubscription::query()->firstOrNew([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
        ]);

        if ($subscription->exists) {
            return $subscription;
        }

        $trialDays = max(0, (int) ($data['trial_days'] ?? config('saas.billing.trial_days', 90)));

        $subscription->fill([
            'status' => $trialDays > 0 ? 'trial' : 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'cancelled_at' => null,
            'overrides' => [],
        ])->save();

        return $subscription;
    }

    private function settings(Tenant $tenant, Branch $branch, array $data): void
    {
        Setting::setMany([
            'default_currency' => $branch->currency,
            'default_timezone' => $branch->timezone,
            'default_country' => $branch->country_code,
            'pos_waiter_status_flow_enabled' => true,
            'printer_auto_kot_enabled' => false,
            'customer_order_auto_print_mode' => 'disabled',
            'payment_default_methods' => PaymentMethod::values(),
            'invoice_show_logo' => true,
            'theme_primary_color' => $data['primary_color'] ?? '#ff6b00',
            'theme_secondary_color' => $data['secondary_color'] ?? '#0f172a',
            'restaurant_logo_url' => $data['logo_url'] ?? null,
            'restaurant_legal_name' => $tenant->legal_name,
            'restaurant_address' => $data['address'] ?? null,
            'restaurant_city' => $data['city'] ?? null,
            'restaurant_state' => $data['state'] ?? null,
            'restaurant_postal_code' => $data['postal_code'] ?? null,
            'restaurant_gst_number' => $data['gst_number'] ?? null,
            'restaurant_phone' => $branch->phone,
            'restaurant_email' => $branch->email,
            'online_order_kot_release_policy' => 'after_payment_or_approval',
            'whatsapp_order_greeting_keywords' => ['hi', 'hii', 'hiii', 'hey', 'hello', 'start', 'menu'],
            'translatable' => [
                'app_name' => $tenant->name,
                'restaurant_name' => $tenant->name,
            ],
        ]);
    }

    public function restaurantSkeleton(Branch $branch): void
    {
        $menu = Menu::query()->withOutGlobalBranchPermission()->firstOrCreate(
            ['branch_id' => $branch->id],
            ['name' => ['en' => 'Main Menu'], 'description' => ['en' => 'Default menu'], 'order_types' => OrderType::values(), 'is_active' => true]
        );

        foreach (['Starters', 'Main Course', 'Beverages'] as $name) {
            Category::query()->firstOrCreate(
                ['menu_id' => $menu->id, 'slug' => Str::slug($name)],
                ['name' => ['en' => $name], 'is_active' => true]
            );
        }

        $floor = Floor::query()->withOutGlobalBranchPermission()->firstOrCreate(
            ['branch_id' => $branch->id, 'order' => 1],
            ['name' => ['en' => 'Floor 1'], 'layout_width' => 1200, 'layout_height' => 800, 'is_active' => true]
        );

        $zones = collect(['Indoor', 'Outdoor'])->map(function (string $name, int $index) use ($branch, $floor) {
            $zone = Zone::query()
                ->withOutGlobalBranchPermission()
                ->where('branch_id', $branch->id)
                ->where('floor_id', $floor->id)
                ->where('color', $index === 0 ? '#0ea5e9' : '#22c55e')
                ->first();

            return $zone ?: Zone::query()
                ->withOutGlobalBranchPermission()
                ->create([
                    'branch_id' => $branch->id,
                    'floor_id' => $floor->id,
                    'name' => ['en' => $name],
                    'color' => $index === 0 ? '#0ea5e9' : '#22c55e',
                    'is_active' => true,
                ]);
        });

        for ($i = 1; $i <= 6; $i++) {
            $existingTable = Table::query()
                ->withOutGlobalBranchPermission()
                ->where('branch_id', $branch->id)
                ->where('floor_id', $floor->id)
                ->where('pos_x', 120 + (($i - 1) % 3) * 180)
                ->where('pos_y', 120 + intdiv($i - 1, 3) * 180)
                ->first();

            if (! $existingTable) {
                Table::query()->withOutGlobalBranchPermission()->create([
                    'branch_id' => $branch->id,
                    'floor_id' => $floor->id,
                    'name' => ['en' => 'T'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)],
                    'zone_id' => $zones[($i - 1) % 2]->id,
                    'capacity' => $i <= 2 ? 2 : 4,
                    'status' => TableStatus::Available,
                    'shape' => $i % 2 === 0 ? TableShape::Rectangle : TableShape::Square,
                    'pos_x' => 120 + (($i - 1) % 3) * 180,
                    'pos_y' => 120 + intdiv($i - 1, 3) * 180,
                    'scale' => 1,
                    'rotation' => 0,
                    'is_active' => true,
                ]);
            }
        }

        foreach ([['CGST', GstType::CGST], ['SGST', GstType::SGST]] as [$code, $type]) {
            Tax::query()->withOutGlobalBranchPermission()->firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $code.'-2.5'],
                ['name' => ['en' => $code.' 2.5%'], 'rate' => 2.5, 'type' => TaxType::Exclusive, 'gst_type' => $type, 'compound' => false, 'is_global' => false, 'order_types' => OrderType::values(), 'is_active' => true]
            );
        }
    }

    public function healthRecord(Tenant $tenant): void
    {
        Storage::disk('local')->put("tenants/{$tenant->id}/health.json", json_encode([
            'tenant_id' => $tenant->id,
            'status' => 'provisioned',
            'queue' => 'unknown',
            'redis' => config('cache.default') === 'redis' ? 'configured' : 'not_configured',
            'storage' => 'ready',
            'printer' => 'not_configured',
            'websocket' => config('broadcasting.default'),
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    private function urls(Tenant $tenant, Branch $branch): array
    {
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $apiUrl = rtrim((string) config('saas.self_service.public_api_base_url'), '/');

        return [
            'restaurant_url' => "{$scheme}://{$tenant->domain}",
            'api_url' => $apiUrl,
            'qr_url' => config('app.url')."/qr/branches/{$branch->id}",
            'client_config_url' => "{$apiUrl}/saas/client-config/{$tenant->slug}",
            'activation_url' => "{$apiUrl}/saas/client-config/{$tenant->slug}/activation",
            'downloads' => collect(config('saas.self_service.downloads', []))->filter()->all(),
        ];
    }

    private function domain(string $slug): string
    {
        $root = config('saas.root_domain') ?: parse_url(config('app.url'), PHP_URL_HOST);

        return "{$slug}.{$root}";
    }

    private function provisioningRun(Tenant $tenant, Branch $branch): SaasProvisioningRun
    {
        $steps = ProvisioningWorkflow::initialSteps();

        foreach (['tenant_created', 'branch_created', 'admin_created', 'subscription_created', 'settings_ready'] as $step) {
            $steps[$step]['status'] = 'completed';
            $steps[$step]['started_at'] = now()->toIso8601String();
            $steps[$step]['completed_at'] = now()->toIso8601String();
            $steps[$step]['duration_ms'] = 0;
            $steps[$step]['logs'][] = [
                'level' => 'info',
                'message' => 'Completed synchronously during tenant creation.',
                'at' => now()->toIso8601String(),
            ];
        }

        return SaasProvisioningRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'status' => 'processing',
            'progress' => 45,
            'current_step' => 'storage_ready',
            'steps' => $steps,
            'metadata' => [
                'restaurant_url' => "https://{$tenant->domain}",
                'created_from' => app()->runningInConsole() ? 'console' : 'api',
            ],
            'started_at' => now(),
        ]);
    }

    private function dispatchPostProvisioning(SaasProvisioningRun $run): void
    {
        app(ProvisioningOrchestratorService::class)->dispatch($run);
    }

    private function deliveryJobs(Tenant $tenant, SaasProvisioningRun $run, array $data): array
    {
        $jobs = [];
        $serverAutomation = $data['server_automation'] ?? null;

        if ($serverAutomation === null && config('saas.server_automation.automatic_tenant_ssl')) {
            $serverAutomation = [
                'mode' => 'tenant_ssl',
                'tenant_domain' => $tenant->domain,
                'apply' => true,
                'email' => config('saas.server_automation.ssl_email') ?: $tenant->contact_email,
            ];
        }

        if (! empty($serverAutomation)) {
            $payload = $this->serverAutomationPayload($tenant, $serverAutomation);
            $jobs[] = SaasDeliveryJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'provisioning_run_id' => $run->id,
                'type' => 'server_automation',
                'payload' => $payload,
            ]);
        }

        if (! empty($data['white_label_build'])) {
            $jobs[] = SaasDeliveryJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'provisioning_run_id' => $run->id,
                'type' => 'waiter_app_build',
                'payload' => [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'config_url' => rtrim((string) config('saas.self_service.public_api_base_url'), '/')."/saas/client-config/{$tenant->slug}",
                    'brand' => $tenant->settings['theme'] ?? [],
                ],
            ]);
        }

        return $jobs;
    }

    private function dispatchDeliveryJobs(array $jobs): void
    {
        foreach ($jobs as $job) {
            match ($job->type) {
                'server_automation' => RunTenantServerAutomationJob::dispatch($job->id)->onQueue(config('saas.queues.delivery', 'delivery')),
                'waiter_app_build' => TriggerWaiterAppBuildJob::dispatch($job->id)->onQueue(config('saas.queues.delivery', 'delivery')),
                default => null,
            };
        }
    }

    private function serverAutomationPayload(Tenant $tenant, array $data): array
    {
        $rootDomain = $data['root_domain'] ?? config('saas.root_domain');
        $tenantDomains = $data['tenant_domains'] ?? Tenant::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNotNull('domain')
            ->pluck('domain')
            ->push($tenant->domain)
            ->filter()
            ->unique()
            ->implode(',');

        return [
            'mode' => $data['mode'] ?? null,
            'tenant_domain' => $data['tenant_domain'] ?? null,
            'web_server' => $data['web_server'] ?? config('saas.server_automation.web_server', 'apache'),
            'api_domain' => $data['api_domain'] ?? parse_url(config('app.url'), PHP_URL_HOST),
            'root_domain' => $rootDomain,
            'tenant_domains' => $tenantDomains,
            'frontend_root' => $data['frontend_root'] ?? config('saas.server_automation.frontend_root'),
            'api_root' => $data['api_root'] ?? config('saas.server_automation.api_root'),
            'email' => $data['email'] ?? $tenant->contact_email,
            'install_packages' => (bool) ($data['install_packages'] ?? false),
            'apply' => (bool) ($data['apply'] ?? false),
        ];
    }
}
