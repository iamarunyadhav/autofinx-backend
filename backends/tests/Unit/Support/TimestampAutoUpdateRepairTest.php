<?php

namespace Tests\Unit\Support;

use App\Support\Database\TimestampAutoUpdateRepair;
use Tests\TestCase;

/**
 * The SQL builder only; the ALTER itself was exercised against MariaDB on
 * scratch tables (values kept, ON UPDATE gone, ALGORITHM=INSTANT). SQLite has
 * no ON UPDATE timestamps, so columns() is empty and the migration a no-op.
 */
class TimestampAutoUpdateRepairTest extends TestCase
{
    private function column(string $type, string $nullable, ?string $default, string $comment = ''): object
    {
        return (object) ['TABLE_NAME' => 't', 'COLUMN_NAME' => 'c', 'COLUMN_TYPE' => $type,
            'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => $default, 'COLUMN_COMMENT' => $comment];
    }

    public function test_keeps_type_nullability_and_an_explicit_default_without_on_update(): void
    {
        $this->assertSame('MODIFY `c` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
            TimestampAutoUpdateRepair::modifyClause($this->column('timestamp', 'NO', 'current_timestamp()')));
        $this->assertSame('MODIFY `c` timestamp(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)',
            TimestampAutoUpdateRepair::modifyClause($this->column('timestamp(3)', 'NO', 'CURRENT_TIMESTAMP(3)')));
        $this->assertSame('MODIFY `c` timestamp(6) NULL DEFAULT NULL',
            TimestampAutoUpdateRepair::modifyClause($this->column('timestamp(6)', 'YES', null)));
        // NOT NULL with no default must still get one, or the server setting
        // would attach the auto-update again.
        $this->assertSame('MODIFY `c` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
            TimestampAutoUpdateRepair::modifyClause($this->column('timestamp', 'NO', null)));
        $this->assertStringNotContainsStringIgnoringCase('ON UPDATE',
            TimestampAutoUpdateRepair::modifyClause($this->column('timestamp', 'NO', "'2026-01-01 00:00:00'", "it's")));
    }

    public function test_sqlite_has_nothing_to_repair(): void
    {
        $this->assertFalse(TimestampAutoUpdateRepair::supported());
        $this->assertSame([], TimestampAutoUpdateRepair::columns());
    }
}
