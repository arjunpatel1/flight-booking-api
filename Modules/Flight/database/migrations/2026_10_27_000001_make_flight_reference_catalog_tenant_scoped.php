<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes airports, airlines, aircraft and cabin classes private per tenant.
 *
 * Existing rows become the platform starter template (tenant_id NULL). Every
 * existing tenant receives its own copy, and that tenant's routes,
 * inventories, schedules, instances, commission rules and saved route catalog
 * are re-pointed from the template rows to its copies.
 *
 * Self-contained on purpose (query builder only): it must keep working after
 * the models change.
 */
return new class extends Migration
{
    /** table => [natural key, old unique index, new unique index] */
    private const TABLES = [
        'flight_airports' => ['iata_code', 'flight_airports_iata_code_unique', 'flight_airports_tenant_iata_unique'],
        'flight_airlines' => ['iata_code', 'flight_airlines_iata_code_unique', 'flight_airlines_tenant_iata_unique'],
        'flight_aircraft' => ['iata_code', 'flight_aircraft_iata_code_unique', 'flight_aircraft_tenant_iata_unique'],
        'flight_cabin_classes' => ['code', 'flight_cabin_classes_code_unique', 'flight_cabin_classes_tenant_code_unique'],
    ];

    /** referencing table => [column => referenced table] */
    private const REFERENCES = [
        'flight_routes' => ['origin_airport_id' => 'flight_airports', 'destination_airport_id' => 'flight_airports'],
        'flight_inventories' => [
            'origin_airport_id' => 'flight_airports',
            'destination_airport_id' => 'flight_airports',
            'airline_id' => 'flight_airlines',
            'aircraft_id' => 'flight_aircraft',
        ],
        'flight_schedules' => ['airline_id' => 'flight_airlines'],
        'flight_instances' => ['aircraft_id' => 'flight_aircraft'],
        'flight_commission_rules' => [
            'origin_airport_id' => 'flight_airports',
            'destination_airport_id' => 'flight_airports',
            'airline_id' => 'flight_airlines',
        ],
    ];

    /** Route-catalog JSON keys (tenants.settings) holding airport ids. */
    private const CATALOG_KEYS = ['origin_airport_id', 'destination_airport_id'];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$key, $oldUnique, $newUnique]) {
            Schema::table($table, function (Blueprint $blueprint) use ($key, $oldUnique, $newUnique) {
                $blueprint->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
                $blueprint->dropUnique($oldUnique);
                $blueprint->unique(['tenant_id', $key], $newUnique);
            });
        }

        DB::transaction(function () {
            foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
                // A tenant that never used the shared list starts with an empty
                // catalog of its own, like any new tenant.
                if (! $this->usesCatalog((int) $tenantId)) {
                    continue;
                }

                $maps = $this->copyTemplateTo((int) $tenantId);
                $this->remapReferences((int) $tenantId, $maps);
                $this->remapRouteCatalog((int) $tenantId, $maps['flight_airports']);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
                // Point the tenant back at the template row with the same code.
                $maps = [];
                foreach (self::TABLES as $table => [$key]) {
                    $template = DB::table($table)->whereNull('tenant_id')->pluck('id', $key);
                    $maps[$table] = [];
                    foreach (DB::table($table)->where('tenant_id', $tenantId)->get(['id', $key]) as $row) {
                        if (isset($template[$row->{$key}])) {
                            $maps[$table][$row->id] = (int) $template[$row->{$key}];
                        }
                    }
                }
                $this->remapReferences((int) $tenantId, $maps);
                $this->remapRouteCatalog((int) $tenantId, $maps['flight_airports']);
            }

            foreach (array_keys(self::TABLES) as $table) {
                // Tenant-only rows with no template twin are dropped: the old
                // schema had a single global list and could not hold them.
                DB::table($table)->whereNotNull('tenant_id')->delete();
            }
        });

        foreach (self::TABLES as $table => [$key, $oldUnique, $newUnique]) {
            Schema::table($table, function (Blueprint $blueprint) use ($key, $oldUnique, $newUnique) {
                $blueprint->dropUnique($newUnique);
                $blueprint->dropConstrainedForeignId('tenant_id');
                $blueprint->unique($key, $oldUnique);
            });
        }
    }

    private function usesCatalog(int $tenantId): bool
    {
        foreach (self::REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $used = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($columns) {
                    foreach (array_keys($columns) as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->exists();

            if ($used) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<int, int>> table => [template id => copy id] */
    private function copyTemplateTo(int $tenantId): array
    {
        $maps = [];

        foreach (self::TABLES as $table => [$key]) {
            $maps[$table] = [];
            $existing = DB::table($table)->where('tenant_id', $tenantId)->pluck('id', $key);

            foreach (DB::table($table)->whereNull('tenant_id')->orderBy('id')->get() as $template) {
                if (isset($existing[$template->{$key}])) {
                    $maps[$table][$template->id] = (int) $existing[$template->{$key}];
                    continue;
                }

                $row = (array) $template;
                unset($row['id']);
                $row['tenant_id'] = $tenantId;

                $maps[$table][$template->id] = (int) DB::table($table)->insertGetId($row);
            }
        }

        return $maps;
    }

    /** @param array<string, array<int, int>> $maps */
    private function remapReferences(int $tenantId, array $maps): void
    {
        foreach (self::REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            foreach ($columns as $column => $referenced) {
                foreach ($maps[$referenced] as $from => $to) {
                    if ($from === $to) {
                        continue;
                    }

                    DB::table($table)
                        ->where('tenant_id', $tenantId)
                        ->where($column, $from)
                        ->update([$column => $to]);
                }
            }
        }
    }

    /** @param array<int, int> $airportMap */
    private function remapRouteCatalog(int $tenantId, array $airportMap): void
    {
        $settings = json_decode((string) DB::table('tenants')->where('id', $tenantId)->value('settings'), true);
        $catalog = data_get($settings, 'flight_booking.configurations.route_catalog');

        if (! is_array($catalog) || $catalog === []) {
            return;
        }

        foreach ($catalog as $index => $route) {
            foreach (self::CATALOG_KEYS as $field) {
                $id = is_array($route) ? ($route[$field] ?? null) : null;
                if (is_numeric($id) && isset($airportMap[(int) $id])) {
                    $catalog[$index][$field] = $airportMap[(int) $id];
                }
            }
        }

        data_set($settings, 'flight_booking.configurations.route_catalog', $catalog);
        DB::table('tenants')->where('id', $tenantId)->update(['settings' => json_encode($settings)]);
    }
};
