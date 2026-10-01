<?php

namespace Modules\Import\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Import\Enums\ImportType;
use Modules\Import\Http\Requests\Api\V1\StoreImportRequest;
use Modules\Import\Services\Import\ImportServiceInterface;
use Modules\Import\Transformers\Api\V1\ImportBatchResource;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\Menu;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;
use Modules\User\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private readonly ImportServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get($request->get('filters', []), $request->get('sorts', [])),
            resource: ImportBatchResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null,
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new ImportBatchResource($this->service->show($id)));
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::created(
            body: new ImportBatchResource($this->service->create(
                ImportType::from($data['type']),
                $request->file('file'),
                $data['options'] ?? [],
            )),
            resource: __('import::imports.import'),
        );
    }

    public function retry(int $id): JsonResponse
    {
        return ApiResponse::success(
            new ImportBatchResource($this->service->retry($id)),
            __('import::imports.queued')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return ApiResponse::success(message: __('import::imports.deleted'));
    }

    public function template(ImportType $type): StreamedResponse
    {
        $headers = match ($type) {
            ImportType::Menus => ['name_en', 'name_ar', 'description_en', 'description_ar', 'branch_id', 'order_types', 'is_active'],
            ImportType::Orders => ['branch_id', 'customer_id', 'waiter_id', 'table_id', 'type', 'status', 'payment_status', 'order_date', 'guest_count', 'product_ids', 'product_skus', 'quantities', 'notes'],
            ImportType::Products => ['name_en', 'name_ar', 'description_en', 'description_ar', 'menu_id', 'sku', 'price', 'category_ids', 'category_name', 'half_price', 'full_price', 'tax_ids', 'is_active', 'is_available', 'is_recommended', 'is_best_seller', 'display_priority'],
            ImportType::Users => ['name', 'username', 'email', 'password', 'gender', 'role_id', 'branch_id', 'tenant_id', 'is_active'],
        };
        // Hardcoded placeholder ids ("branch_id = 1") do not exist in every
        // installation, so the downloaded template failed validation on its own
        // sample row. Resolve ids the caller can actually use.
        $branchId = $this->sampleId(Branch::query());
        $menuId = $this->sampleId(Menu::query());
        $waiterRoleId = $this->sampleId(
            Role::query()->orderByRaw("FIELD(name, 'waiter') DESC")
        );

        $sample = match ($type) {
            ImportType::Menus => ['Seasonal Menu', 'القائمة الموسمية', 'Limited seasonal dishes', 'أطباق موسمية محدودة', $branchId, 'dine_in|takeaway', '1'],
            ImportType::Orders => [$branchId, '', '', '', 'dine_in', 'pending', 'unpaid', now()->toDateString(), '2', '', 'SKU-001', '1', 'Sample dine-in import order'],
            ImportType::Products => ['Margherita Pizza', 'بيتزا مارغريتا', 'Classic tomato and cheese pizza', 'بيتزا طماطم وجبن كلاسيكية', $menuId, 'SKU-001', '249.00', '', '', '', '', '', '1', '1', '0', '1', '10'],
            ImportType::Users => ['Demo Waiter', 'demo_waiter', 'waiter@example.com', 'password', 'male', $waiterRoleId, $branchId, '', '1'],
        };

        // A sample row is useful only when all of its required references are
        // real records visible to the current tenant. On a fresh restaurant,
        // return a header-only template instead of a row which is guaranteed
        // to fail validation.
        $hasRequiredReferences = match ($type) {
            ImportType::Menus, ImportType::Orders => $branchId !== '',
            ImportType::Products => $menuId !== '',
            ImportType::Users => $branchId !== '' && $waiterRoleId !== '',
        };

        return response()->streamDownload(function () use ($headers, $sample, $hasRequiredReferences) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            if ($hasRequiredReferences) {
                fputcsv($stream, $sample);
            }
            fclose($stream);
        }, "{$type->value}-import-template.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Lowest id the caller can see, as a string for the CSV.
     *
     * An empty value is intentionally used when no valid reference exists.
     * A fabricated id such as 1 made the official sample fail with a misleading
     * foreign-reference error on fresh restaurants.
     */
    private function sampleId(\Illuminate\Database\Eloquent\Builder $query): string
    {
        return (string) ($query->reorder()->orderBy('id')->value('id') ?? '');
    }

    public function failures(int $id): StreamedResponse
    {
        $batch = $this->service->show($id);

        return response()->streamDownload(function () use ($batch) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['row', 'message']);
            foreach ($batch->errors ?? [] as $error) {
                fputcsv($stream, [
                    $error['row'] ?? null,
                    $error['message'] ?? null,
                ]);
            }
            fclose($stream);
        }, "import-{$batch->id}-failures.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'types' => collect(ImportType::cases())->map(fn(ImportType $type) => [
                'id' => $type->value,
                'name' => __("import::imports.types.{$type->value}"),
            ])->values(),
            'branches' => Branch::list(),
            'menus' => Menu::list(withoutGlobalActive: true),
            'roles' => Role::list(withCustomer: false),
            'tenants' => Tenant::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
