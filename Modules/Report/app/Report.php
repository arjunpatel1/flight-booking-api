<?php

namespace Modules\Report;

use App\NexDine;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Report\Contracts\ReportInterface;
use Modules\Report\Traits\HasExportReport;
use Modules\Support\Enums\GroupDateType;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\User;

abstract class Report implements ReportInterface
{
    use HasExportReport;

    /**
     * User Assigned branch
     */
    public Branch $branch;

    /**
     * Determine if you use currency_rate or not
     */
    public bool $withRate = false;

    /**
     * Authentication user
     */
    public ?User $user = null;

    /**
     * Use currency
     */
    public string $currency;

    /**
     * Create a new instance of Report
     */
    public function __construct()
    {
        $this->user = auth()->user();

        if ($this->user?->assignedToBranch()) {
            $this->branch = $this->user->branch;
            $this->currency = $this->branch->currency;
        } else {
            $this->currency = setting('default_currency');
            $this->withRate = true;
        }
    }

    /** {@inheritDoc} */
    public function permission(): string
    {
        return "admin.reports.{$this->key()}";
    }

    /** {@inheritDoc} */
    public function transAttributes(): array
    {
        return $this->attributes()
            ->mapWithKeys(fn ($attribute, $key) => [
                $key => __("report::attributes.{$this->key()}.$attribute"),
            ])
            ->toArray();
    }

    /** {@inheritDoc} */
    public function render(Request $request): array
    {
        $data = $this->data($request);

        return [
            'key' => $this->key(),
            'label' => $this->transLabel(),
            'filters' => [
                ...$this->globalFilters(),
                ...$this->filters($request),
            ],
            'headers' => $this->attributes()
                ->map(fn ($attribute) => [
                    'title' => __("report::attributes.{$this->key()}.$attribute"),
                    'value' => $attribute,
                    'sortable' => false,
                ]),
            'export_methods' => $this->exportMethods(),
            'has_search' => $this->hasSearch(),
            ...$data,
            'summary' => $this->summary($request, $data),
        ];
    }

    /**
     * Return totals for the complete filtered result, not only the active page.
     * Individual reports may add domain-specific totals.
     */
    protected function summary(Request $request, array $data): array
    {
        return [[
            'key' => 'filtered_records',
            'label' => __('report::reports.filtered_records'),
            'value' => (int) ($data['pagination']['total'] ?? count($data['data'] ?? [])),
            'icon' => 'tabler-list-numbers',
            'color' => 'secondary',
        ]];
    }

    /** {@inheritDoc} */
    public function transLabel(): string
    {
        return __($this->label());
    }

    /** {@inheritDoc} */
    public function label(): string
    {
        return "report::reports.definitions.{$this->key()}.title";
    }

    /**
     * Get global filters
     */
    public function globalFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            GlobalStructureFilters::from(__('report::reports.filters.start_date')),
            GlobalStructureFilters::to(__('report::reports.filters.end_date')),
            GlobalStructureFilters::groupByDate(),
        ];
    }

    /** {@inheritDoc} */
    public function filters(Request $request): array
    {
        return [];
    }

    /** {@inheritDoc} */
    public function hasSearch(): bool
    {
        return false;
    }

    /** {@inheritDoc} */
    public function data(Request $request, bool $withPagination = true): array|Collection
    {
        $this->resolveDefaultDateColumn();
        $query = app(Pipeline::class)
            ->send($this->scopeToAuthenticatedTenant($this->model()::query()))
            ->through($this->through($request))
            ->thenReturn()
            ->with($this->with())
            ->selectRaw(implode(',', $this->columns()))
            ->when(
                ! $this->hasGroupByData($request),
                fn ($query) => $query->latest($this->model()::$defaultDateColumn ?? 'created_at')
            )
            ->filters($request->get('filters', []));

        if ($withPagination) {
            $data = $query->paginate(NexDine::paginate())->withQueryString();

            return [
                'data' => array_map(fn ($item) => $this->resource($item), $data->items()),
                'pagination' => [
                    'current_page' => $data->currentPage(),
                    'from' => $data->firstItem(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'to' => $data->lastItem(),
                    'total' => $data->total(),
                ],
            ];
        } else {
            return $query->get()->map(fn ($item) => $this->resource($item));
        }
    }

    /**
     * Resolve default date column
     */
    protected function resolveDefaultDateColumn(): void {}

    /**
     * Report models do not all carry a tenant_id directly, but every
     * operational row is either tenant-owned or branch-owned.  Do this before
     * report-specific joins and filters so a tenant supplied branch filter can
     * only narrow its own data, never select another restaurant's records.
     */
    protected function scopeToAuthenticatedTenant(Builder $query): Builder
    {
        $user = $this->user;
        if (! $user?->assignedToTenant()) {
            return $query;
        }

        $model = $query->getModel();
        $table = $model->getTable();
        $tenantId = (int) $user->tenant_id;
        $hasTenantColumn = Schema::hasColumn($table, 'tenant_id');
        $hasBranchColumn = Schema::hasColumn($table, 'branch_id');

        if (! $hasTenantColumn && ! $hasBranchColumn) {
            // A report with no ownership column must explicitly define its own
            // ownership join instead of silently returning cross-tenant data.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $owned) use ($table, $tenantId, $hasTenantColumn, $hasBranchColumn): void {
            if ($hasBranchColumn) {
                $owned->whereIn("{$table}.branch_id", Branch::query()
                    ->withoutGlobalScopes()
                    ->select('id')
                    ->where('tenant_id', $tenantId));
            }
            if ($hasTenantColumn) {
                $method = $hasBranchColumn ? 'orWhere' : 'where';
                $owned->{$method}("{$table}.tenant_id", $tenantId);
            }
        });
    }

    /** {@inheritDoc} */
    public function with(): array
    {
        return [];
    }

    /**
     * Determine if user has filter in group by date
     */
    protected function hasGroupByData(Request $request): bool
    {
        return isset($request->get('filters', [])['group_by_date']) && in_array($request->get('filters', [])['group_by_date'], GroupDateType::values());
    }
}
