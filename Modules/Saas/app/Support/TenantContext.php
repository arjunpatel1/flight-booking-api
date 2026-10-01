<?php

namespace Modules\Saas\Support;

use Modules\Saas\Models\Tenant;

class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * The tenant id when known without a loaded model — set by the queue
     * context hook, which carries only the id in the job payload to stay
     * query-free on the hot path. The model is resolved lazily if needed.
     */
    private ?int $tenantId = null;

    private ?string $tenantSlug = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->tenantId = $tenant?->id;
        $this->tenantSlug = $tenant?->slug;
    }

    /**
     * Set context from an id alone (queue workers, where no model is loaded and
     * a DB query per job is undesirable). The model resolves lazily via get().
     */
    public function setId(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
        // Drop any stale loaded model/slug that no longer matches.
        if ($this->tenant && $this->tenant->id !== $tenantId) {
            $this->tenant = null;
            $this->tenantSlug = null;
        }
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->tenantId = null;
        $this->tenantSlug = null;
    }

    public function get(): ?Tenant
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Tenant::query()->withoutGlobalScopes()->find($this->tenantId);
            $this->tenantSlug = $this->tenant?->slug;
        }

        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenantId ?? $this->tenant?->id;
    }

    /**
     * Stable, human-readable tenant token. Resolved lazily; may trigger one
     * query if only the id is known. Prefer id() on hot paths.
     */
    public function slug(): ?string
    {
        return $this->tenantSlug ??= $this->get()?->slug;
    }

    public function hasTenant(): bool
    {
        return $this->id() !== null;
    }
}
