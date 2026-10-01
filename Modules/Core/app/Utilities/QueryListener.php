<?php

namespace Modules\Core\Utilities;

use DateTime;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueryListener
{
    /**
     * Executed Query Counter
     *
     * @var int
     */
    protected static int $queriesCount = 0;

    /**
     * Listen to all executed queries
     *
     * @return void
     */
    public static function listen(): void
    {
        if (!app()->environment(['local', 'testing']) || !config('database.enable_query_log')) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {

            $sql = $query->sql;

            $bindings = $query->bindings;

            // Convert all bindings safely (binary-safe)
            $safeBindings = array_map(function ($binding) {
                if (is_string($binding)) {
                    // Replace null bytes with \0 so logging doesn't crash
                    return addcslashes($binding, "\0");
                }
                if ($binding instanceof DateTime) {
                    return $binding->format('Y-m-d H:i:s');
                }
                return $binding;
            }, $bindings);

            // Insert bindings into SQL manually (binary-safe)
            $fullSql = vsprintf(
                str_replace(['%', '?'], ['%%', '%s'], $sql),
                array_map(function ($value) {
                    return is_string($value) ? "'{$value}'" : $value;
                }, $safeBindings)
            );

            self::$queriesCount++;
            Log::debug($fullSql);
            Log::debug("execution time: " . $query->time . ' ms');
            Log::debug('Total Queries Executed: ' . self::$queriesCount);
            Log::debug('--------------------');
        });
    }
}
