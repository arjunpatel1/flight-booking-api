<?php

namespace Modules\ActivityLog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasUserAgent;
use Modules\User\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * @method static Builder|self whereIp(string $ip)
 * @method static Builder|self whereBatchUuid(string $batchUuid)
 * @method static Builder|self whereEvent(string $event)
 * @method static Builder|self whereLogName(string $logName)
 * @method static Builder|self whereCauserUser(int $id)
 */
class ActivityLog extends Activity
{
    use HasSortBy, HasFilters, HasUserAgent;

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'ip',
            'batch_uuid',
            'causer_user',
            'event',
            'log_name',
            'from',
            'to'
        ];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $value = mb_substr(trim($value), 0, 200);
        if ($value === '') return;
        $query->where(function (Builder $search) use ($value) {
            $search->where('properties->info->ip', 'like', '%'.$value.'%')
                ->orWhere('description', 'like', '%'.$value.'%')
                ->orWhere('event', 'like', '%'.$value.'%')
                ->orWhere('log_name', 'like', '%'.$value.'%');
        });
    }

    /**
     * Scope a query to get by log name.
     *
     * @param Builder $query
     * @param string|array $logName
     * @return void
     */
    public function scopeWhereLogName(Builder $query, string|array $logName): void
    {
        $query->whereIn("log_name", Arr::wrap($logName));
    }

    /**
     * Scope a query to get by Causer User
     *
     * @param Builder $query
     * @param int $id
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeWhereCauserUser(Builder $query, int $id): void
    {
        $query->where("causer_id", $id)->where('causer_type', User::class);
    }

    /** @inheritDoc */
    function getUserAgent(): ?string
    {
        return $this->properties["info"]["user_agent"] ?? null;
    }

    /**
     * Get subject text
     * @return string
     */
    public function getSubjectText(): string
    {
        $parse = $this->parseSubject();

        // Operational entries (cache clear, Horizon control) target no model.
        if (blank($parse['model_text'])) {
            return '';
        }

        return "{$parse['model_text']} #{$this->subject_id}";
    }

    /**
     * Parse subject info
     * @return array
     */
    public function parseSubject(): array
    {
        // Not every activity has a subject: operational commands log an action
        // without a model. Reading $pieces[1] unconditionally raised
        // "Undefined array key 1" and broke the whole audit log listing.
        if (blank($this->subject_type)) {
            return ["module" => null, "model" => null, "model_text" => null];
        }

        $pieces = explode("\\", $this->subject_type);
        $model = str(last($pieces))->snake()->lower();
        $module = mb_strtolower($pieces[1] ?? $pieces[0]);

        return [
            "module" => $module,
            "model" => $model,
            "model_text" => __("$module::{$model->snake()->plural()}.$model")
        ];
    }

    /**
     * The attributes that can be used for sorting.
     *
     * @return array
     */
    protected function getSortableAttributes(): array
    {
        return [
            'log_name',
            'subject_id',
            'subject_type',
            'event',
        ];
    }

    /**
     * Scope a query to get by ip.
     *
     * @param Builder $query
     * @param string $ip
     * @return void
     * @noinspection PhpUnused
     */
    protected function scopeWhereIp(Builder $query, string $ip): void
    {
        $query->where("properties->info->ip", $ip);
    }

    /**
     * Scope a query to get by batch uuid.
     *
     * @param Builder $query
     * @param string $batchUuid
     * @return void
     * @noinspection PhpUnused
     */
    protected function scopeWhereBatchUuid(Builder $query, string $batchUuid): void
    {
        $query->where("batch_uuid", $batchUuid);
    }

    /**
     * Scope a query to get by event.
     *
     * @param Builder $query
     * @param string|array $event
     * @return void
     * @noinspection PhpUnused
     */
    protected function scopeWhereEvent(Builder $query, string|array $event): void
    {
        $query->whereIn("event", Arr::wrap($event));
    }
}
