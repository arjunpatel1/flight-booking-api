<?php

namespace Modules\ActivityLog\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasUserAgent;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property string $ip_address
 * @property string $user_agent
 * @property Carbon $login_at
 * @property Carbon $logout_at
 * @property User $authenticatable
 */
class AuthenticationLog extends \Yadahan\AuthenticationLog\AuthenticationLog
{
    use HasSortBy, HasFilters, HasUserAgent;

    /**
     * Default date column
     *
     * @var string
     */
    protected static string $defaultDateColumn = 'login_at';

    /**
     * Get default allowed filters
     *
     * @return array
     */
    public function allowedFilterKeys(): array
    {
        return [
            'search'
        ];
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $query) use ($value) {
            $query->like('ip_address', $value)
                ->orWhereHas('authenticatable', function (Builder $query) use ($value) {
                    $query->like('name', $value)
                        ->orLike('email', $value)
                        ->orLike('username', $value);
                });
        });
    }

    /** @inheritDoc */
    function getUserAgent(): ?string
    {
        return $this->user_agent;
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "ip_address",
            "login_at",
            "logout_at",
        ];
    }
}
