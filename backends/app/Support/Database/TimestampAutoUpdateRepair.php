<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MySQL/MariaDB with explicit_defaults_for_timestamp=OFF give the first
 * TIMESTAMP NOT NULL column of a table DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP. Every UPDATE of such a row then silently overwrites that
 * column with the server clock (+08 on production): decision times, fill
 * times, candle open times and audit times all drift to whenever the row was
 * last touched.
 *
 * This finds those columns and drops only the ON UPDATE clause. The column
 * type, nullability, precision and stored values stay as they are. The
 * DEFAULT is always written explicitly, because a TIMESTAMP NOT NULL without
 * one would get the auto-update back under the same server setting.
 */
class TimestampAutoUpdateRepair
{
    public static function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  list<string>|null  $tables  limit to these tables (null = whole database)
     * @return list<object{TABLE_NAME:string, COLUMN_NAME:string, COLUMN_TYPE:string, IS_NULLABLE:string, COLUMN_DEFAULT:?string, COLUMN_COMMENT:string}>
     */
    public static function columns(?array $tables = null): array
    {
        if (! self::supported()) {
            return [];
        }

        $sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND LOWER(EXTRA) LIKE '%on update current_timestamp%'";
        $bindings = [];
        if ($tables !== null) {
            if ($tables === []) {
                return [];
            }
            $sql .= ' AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tables), '?')).')';
            $bindings = array_values($tables);
        }

        return DB::select($sql.' ORDER BY TABLE_NAME, COLUMN_NAME', $bindings);
    }

    public static function modifyClause(object $column): string
    {
        $type = strtolower((string) $column->COLUMN_TYPE);           // timestamp, timestamp(3), ...
        $precision = preg_match('/\((\d+)\)/', $type, $m) ? "({$m[1]})" : '';
        $nullable = strtoupper((string) $column->IS_NULLABLE) === 'YES';
        $default = $column->COLUMN_DEFAULT;

        if ($default === null || strtoupper((string) $default) === 'NULL') {
            $defaultSql = $nullable ? 'DEFAULT NULL' : "DEFAULT CURRENT_TIMESTAMP{$precision}";
        } elseif (stripos((string) $default, 'current_timestamp') !== false) {
            $defaultSql = "DEFAULT CURRENT_TIMESTAMP{$precision}";
        } else {
            $defaultSql = 'DEFAULT '.DB::getPdo()->quote(trim((string) $default, "'"));
        }

        $comment = trim((string) ($column->COLUMN_COMMENT ?? '')) !== ''
            ? ' COMMENT '.DB::getPdo()->quote((string) $column->COLUMN_COMMENT)
            : '';

        return sprintf('MODIFY `%s` %s %s %s%s', $column->COLUMN_NAME, $type, $nullable ? 'NULL' : 'NOT NULL', $defaultSql, $comment);
    }

    /**
     * Drop the auto-update from one column, preferring algorithms that do not
     * copy the table (these tables can be large and are written live).
     */
    public static function fix(object $column): string
    {
        $base = sprintf('ALTER TABLE `%s` %s', $column->TABLE_NAME, self::modifyClause($column));
        $last = null;
        foreach ([', ALGORITHM=INSTANT', ', ALGORITHM=NOCOPY', ', ALGORITHM=INPLACE, LOCK=NONE', ''] as $algorithm) {
            try {
                DB::statement($base.$algorithm);

                return $algorithm === '' ? 'COPY' : trim(str_replace(['ALGORITHM=', ','], '', $algorithm));
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        throw $last;
    }
}
