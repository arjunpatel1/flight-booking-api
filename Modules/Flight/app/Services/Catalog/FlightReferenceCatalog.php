<?php

namespace Modules\Flight\Services\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Copies the platform starter template (rows with a NULL tenant_id) into a
 * tenant's own reference catalog.
 *
 * Idempotent: a template row is skipped when the tenant already has a row
 * with the same code, so re-running never duplicates or overwrites a
 * tenant's edits.
 */
class FlightReferenceCatalog
{
    /** table => natural key column */
    public const TABLES = [
        'flight_airports' => 'iata_code',
        'flight_airlines' => 'iata_code',
        'flight_aircraft' => 'iata_code',
        'flight_cabin_classes' => 'code',
    ];

    /**
     * @param  array<int, string>|null  $tables  subset of TABLES to copy (default: all)
     * @return array<string, array<int, int>> table => [template id => tenant id]
     */
    public function copyStarterSet(int $tenantId, ?array $tables = null): array
    {
        $tables = $tables === null ? self::TABLES : array_intersect_key(self::TABLES, array_flip($tables));

        return DB::transaction(function () use ($tenantId, $tables) {
            $maps = [];

            foreach ($tables as $table => $key) {
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
                    $row['created_at'] = $row['updated_at'] = now();

                    $maps[$table][$template->id] = (int) DB::table($table)->insertGetId($row);
                }
            }

            return $maps;
        });
    }
}
