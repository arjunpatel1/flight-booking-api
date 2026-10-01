<?php

namespace Tests\Unit\Support;

use Modules\Support\Enums\DateTimeFormat;
use Tests\TestCase;

class DateTimeFormatHelperTest extends TestCase
{
    public function test_date_time_format_accepts_database_date_strings(): void
    {
        $this->assertSame(
            '2026-05-22',
            dateTimeFormat('2026-05-22 10:15:00', DateTimeFormat::Date)
        );
    }

    public function test_date_time_format_returns_default_for_invalid_strings(): void
    {
        $this->assertSame(
            '-',
            dateTimeFormat('not-a-date', DateTimeFormat::Date, '-')
        );
    }
}
