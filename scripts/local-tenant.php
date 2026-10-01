<?php

/**
 * Creates the local demo tenant through `saas:create-tenant` unless a tenant
 * with that slug already exists. Called by scripts/setup-local.{sh,ps1}.
 *
 *   php scripts/local-tenant.php <slug> <domain> <email> <password> [name]
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Modules\Saas\Models\Tenant;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $slug, $domain, $email, $password] = $argv + array_fill(0, 5, null);
$name = $argv[5] ?? 'Demo Restaurant';

if (! $slug || ! $domain || ! $email || ! $password) {
    fwrite(STDERR, "Usage: php scripts/local-tenant.php <slug> <domain> <email> <password> [name]\n");
    exit(1);
}

$existing = Tenant::query()->withoutGlobalScopes()->withTrashed()->where('slug', $slug)->first();

if ($existing && ! $existing->trashed()) {
    echo "Tenant '{$slug}' already exists (id {$existing->id}, domain {$existing->domain}); skipping.\n";
    exit(0);
}

exit(Artisan::call('saas:create-tenant', [
    '--name' => $name,
    '--slug' => $slug,
    '--email' => $email,
    '--password' => $password,
    '--domain' => $domain,
], new Symfony\Component\Console\Output\ConsoleOutput));
