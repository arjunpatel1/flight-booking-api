<?php

namespace Tests\Unit\Branch;

use Carbon\Carbon;
use Modules\Branch\Models\Branch;
use Modules\Branch\Support\BranchSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Covers the schedule that replaced the hardcoded `is_open => true` in the
 * customer app discovery endpoint.
 */
class BranchScheduleTest extends TestCase
{
    private BranchSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = new BranchSchedule();
    }

    private function branch(?array $hours, bool $accepting = true): Branch
    {
        $branch = new Branch();
        $branch->timezone = 'Asia/Kolkata';
        $branch->opening_hours = $hours;
        $branch->is_accepting_orders = $accepting;

        return $branch;
    }

    private function at(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Kolkata');
    }

    /** @return array<string, list<array{open: string, close: string}>> */
    private function everyDay(string $open, string $close): array
    {
        return array_fill_keys(
            ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
            [['open' => $open, 'close' => $close]],
        );
    }

    public function test_branch_without_a_schedule_stays_open(): void
    {
        $result = $this->schedule->describe($this->branch(null));

        $this->assertTrue($result['is_open']);
        $this->assertNull($result['opens_at']);
        $this->assertNull($result['closes_at']);
    }

    public function test_open_inside_the_window(): void
    {
        $result = $this->schedule->describe(
            $this->branch($this->everyDay('11:00', '23:00')),
            $this->at('2026-08-25 13:00'),
        );

        $this->assertTrue($result['is_open']);
        $this->assertSame('11:00', $result['opens_at']);
        $this->assertSame('23:00', $result['closes_at']);
    }

    public function test_closed_outside_the_window_and_reports_next_opening(): void
    {
        $result = $this->schedule->describe(
            $this->branch($this->everyDay('11:00', '23:00')),
            $this->at('2026-08-25 23:30'),
        );

        $this->assertFalse($result['is_open']);
        $this->assertSame('11:00', $result['opens_at']);
    }

    public function test_window_crossing_midnight_stays_open_after_midnight(): void
    {
        // Monday 18:00 -> 02:00 should still be open at 01:00 on Tuesday.
        $branch = $this->branch(['mon' => [['open' => '18:00', 'close' => '02:00']]]);

        $this->assertTrue(
            $this->schedule->isOpen($branch, $this->at('2026-08-25 01:00')),
        );
        $this->assertFalse(
            $this->schedule->isOpen($branch, $this->at('2026-08-25 03:00')),
        );
    }

    public function test_manual_kill_switch_closes_a_scheduled_branch(): void
    {
        $result = $this->schedule->describe(
            $this->branch($this->everyDay('11:00', '23:00'), accepting: false),
            $this->at('2026-08-25 13:00'),
        );

        $this->assertFalse($result['is_open']);
        $this->assertSame('11:00', $result['opens_at']);
    }

    public function test_kill_switch_closes_a_branch_with_no_schedule(): void
    {
        $this->assertFalse(
            $this->schedule->isOpen($this->branch(null, accepting: false)),
        );
    }

    public function test_day_with_no_windows_is_closed(): void
    {
        $branch = $this->branch([
            'mon' => [['open' => '11:00', 'close' => '23:00']],
            'tue' => [],
        ]);

        // Tuesday has no windows at all.
        $this->assertFalse(
            $this->schedule->isOpen($branch, $this->at('2026-08-25 13:00')),
        );
    }

    public function test_malformed_times_are_ignored_rather_than_crashing(): void
    {
        $branch = $this->branch([
            'mon' => [['open' => '25:00', 'close' => 'nonsense']],
            'notaday' => [['open' => '11:00', 'close' => '23:00']],
        ]);

        // Every window was rejected, so the branch behaves as unscheduled.
        $this->assertTrue(
            $this->schedule->isOpen($branch, $this->at('2026-08-24 13:00')),
        );
    }

    public function test_split_shifts_are_supported(): void
    {
        $branch = $this->branch([
            'tue' => [
                ['open' => '11:00', 'close' => '15:00'],
                ['open' => '19:00', 'close' => '23:00'],
            ],
        ]);

        $this->assertTrue($this->schedule->isOpen($branch, $this->at('2026-08-25 12:00')));
        $this->assertFalse($this->schedule->isOpen($branch, $this->at('2026-08-25 17:00')));
        $this->assertTrue($this->schedule->isOpen($branch, $this->at('2026-08-25 20:00')));
    }

    public function test_schedule_is_evaluated_in_the_branch_timezone(): void
    {
        $branch = $this->branch($this->everyDay('11:00', '23:00'));

        // 07:00 UTC is 12:30 in Kolkata, so the branch is open even though the
        // UTC clock says it should not be.
        $this->assertTrue(
            $this->schedule->isOpen($branch, Carbon::parse('2026-08-25 07:00', 'UTC')),
        );
    }
}
