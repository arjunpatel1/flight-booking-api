<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;

class CreateTenantCommand extends Command
{
    protected $signature = 'saas:create-tenant
        {--name= : Restaurant name}
        {--slug= : Tenant slug}
        {--email= : Admin email}
        {--phone= : Contact phone}
        {--password= : Admin password}
        {--domain= : Tenant domain}
        {--plan=starter : Subscription plan code}
        {--primary-color=#ff6b00 : Primary brand color}
        {--secondary-color=#0f172a : Secondary brand color}';

    protected $description = 'Provision a complete SaaS restaurant tenant.';

    public function handle(SaasProvisioningService $service): int
    {
        foreach (['name', 'email'] as $required) {
            if (blank($this->option($required))) {
                $this->error("--{$required} is required.");

                return self::FAILURE;
            }
        }

        $result = $service->provision([
            'name' => $this->option('name'),
            'slug' => $this->option('slug') ?: $this->option('name'),
            'email' => $this->option('email'),
            'phone' => $this->option('phone'),
            'password' => $this->option('password'),
            'domain' => $this->option('domain'),
            'plan' => $this->option('plan'),
            'primary_color' => $this->option('primary-color'),
            'secondary_color' => $this->option('secondary-color'),
        ]);

        $this->info('Tenant provisioned successfully.');
        $this->table(['Key', 'Value'], [
            ['Tenant ID', $result['tenant']->id],
            ['Tenant', $result['tenant']->name],
            ['Domain', $result['tenant']->domain],
            ['Branch ID', $result['branch']->id],
            ['Admin Email', $result['admin']->email],
            ['Admin Password', $result['password']],
            ['Restaurant URL', $result['urls']['restaurant_url']],
            ['API URL', $result['urls']['api_url']],
            ['QR URL', $result['urls']['qr_url']],
        ]);

        return self::SUCCESS;
    }
}
