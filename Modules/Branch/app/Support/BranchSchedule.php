<?php

namespace Modules\Branch\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\Branch\Models\Branch;

/**
 * Resolves whether a branch is currently open for customer orders.
 *
 * `opening_hours` is stored per weekday as a list of windows, e.g.
 *
 *     {"mon": [{"open": "11:00", "close": "23:00"}], "tue": [], ...}
 *
 * A window whose close time is not after its open time is treated as crossing
 * midnight, so a 18:00-02:00 window keeps the branch open into the next day.
 * Branches with no schedule configured stay open, preserving the behaviour of
 * the hardcoded `is_open => true` this replaces.
 */
class BranchSchedule
{
    /** @var list<string> */
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * Describe the branch's current availability.
     *
     * @return array{is_open: bool, opens_at: string|null, closes_at: string|null}
     */
    public function describe(Branch $branch, ?CarbonInterface $now = null): array
    {
        $windows = $this->windows($branch);
        $closed = ['is_open' => false, 'opens_at' => null, 'closes_at' => null];

        if ($branch->is_accepting_orders === false) {
            // Manual kill switch still reports the schedule so the client can
            // say when the outlet is due to reopen.
            return $windows === []
                ? $closed
                : array_merge($closed, ['opens_at' => $this->nextOpening($windows, $this->localNow($branch, $now))]);
        }

        if ($windows === []) {
            return ['is_open' => true, 'opens_at' => null, 'closes_at' => null];
        }

        $localNow = $this->localNow($branch, $now);

        foreach ($this->activeWindows($windows, $localNow) as $window) {
            if ($localNow->betweenIncluded($window['start'], $window['end'])) {
                return [
                    'is_open' => true,
                    'opens_at' => $window['start']->format('H:i'),
                    'closes_at' => $window['end']->format('H:i'),
                ];
            }
        }

        return array_merge($closed, ['opens_at' => $this->nextOpening($windows, $localNow)]);
    }

    /**
     * Whether the branch is open right now.
     */
    public function isOpen(Branch $branch, ?CarbonInterface $now = null): bool
    {
        return $this->describe($branch, $now)['is_open'];
    }

    /**
     * Normalise the stored schedule into `[day => [[open, close], ...]]`.
     *
     * @return array<string, list<array{open: string, close: string}>>
     */
    private function windows(Branch $branch): array
    {
        $normalized = [];

        foreach ((array) ($branch->opening_hours ?? []) as $day => $ranges) {
            $day = strtolower(substr((string) $day, 0, 3));

            if (! in_array($day, self::DAYS, true) || ! is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                $open = $this->time(data_get($range, 'open'));
                $close = $this->time(data_get($range, 'close'));

                if ($open !== null && $close !== null) {
                    $normalized[$day][] = ['open' => $open, 'close' => $close];
                }
            }
        }

        return $normalized;
    }

    /**
     * Expand today's and yesterday's windows into absolute instants, so a
     * window that crosses midnight is still matched after midnight.
     *
     * @param  array<string, list<array{open: string, close: string}>>  $windows
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function activeWindows(array $windows, Carbon $localNow): array
    {
        $expanded = [];

        foreach ([$localNow->copy()->subDay(), $localNow] as $day) {
            foreach ($windows[self::DAYS[$day->dayOfWeek]] ?? [] as $window) {
                $start = $day->copy()->setTimeFromTimeString($window['open']);
                $end = $day->copy()->setTimeFromTimeString($window['close']);

                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                $expanded[] = ['start' => $start, 'end' => $end];
            }
        }

        return $expanded;
    }

    /**
     * The next opening time within the coming week, as `H:i`.
     *
     * @param  array<string, list<array{open: string, close: string}>>  $windows
     */
    private function nextOpening(array $windows, Carbon $localNow): ?string
    {
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $localNow->copy()->addDays($offset);

            foreach ($windows[self::DAYS[$day->dayOfWeek]] ?? [] as $window) {
                $start = $day->copy()->setTimeFromTimeString($window['open']);

                if ($start->greaterThan($localNow)) {
                    return $start->format('H:i');
                }
            }
        }

        return null;
    }

    /**
     * Current time in the branch's own timezone.
     */
    private function localNow(Branch $branch, ?CarbonInterface $now): Carbon
    {
        $timezone = $branch->timezone ?: config('app.timezone');

        return Carbon::instance($now?->toDateTime() ?? now()->toDateTime())
            ->setTimezone($timezone);
    }

    /**
     * Validate and normalise a stored `HH:MM` value.
     */
    private function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value), $matches)) {
            return null;
        }

        return $matches[1].':'.$matches[2];
    }
}
