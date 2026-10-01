<?php

namespace Modules\Saas\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Counts realtime channel usage per version, so retirement of v1 is a
 * data-driven decision, not a guess.
 *
 * The retirement rule (see the phase-2.5 report): v1 channels may be retired
 * only once v1 *subscriptions* reach zero across a full business cycle. This
 * service is what measures that.
 *
 * Counters are kept in per-day buckets and summed over a rolling window, so the
 * signal is self-clearing: a day with no v1 subscriptions drops out of the
 * window automatically as its bucket expires. Without this, cumulative counters
 * would keep `subscribe_v1 > 0` forever after the first ever v1 subscription and
 * `ready_to_retire_v1` could never become true.
 *
 * Counters are cheap cache increments, tolerant of a missing cache store
 * (returns without error), and never on a critical path.
 */
class RealtimeChannelTelemetry
{
    private const METRICS = [
        'broadcast_v1', 'broadcast_v2', 'subscribe_v1', 'subscribe_v2',
    ];

    /**
     * Rolling measurement window, in days, over which counters are summed.
     * Two days covers a full business cycle including overnight service.
     */
    private const WINDOW_DAYS = 2;

    public function recordBroadcast(string $version, int $channels = 1): void
    {
        $this->bump("broadcast_{$version}", $channels);
    }

    public function recordSubscribe(string $version): void
    {
        $this->bump("subscribe_{$version}");
    }

    /**
     * @return array<string,int|bool>
     */
    public function snapshot(): array
    {
        $out = [];
        foreach (self::METRICS as $metric) {
            $out[$metric] = $this->windowSum($metric);
        }

        $out['v1_active'] = $out['subscribe_v1'] > 0;
        $out['ready_to_retire_v1'] = $out['subscribe_v1'] === 0 && $out['subscribe_v2'] > 0;
        $out['window_days'] = self::WINDOW_DAYS;

        return $out;
    }

    public function reset(): void
    {
        foreach (self::METRICS as $metric) {
            foreach ($this->windowDates() as $date) {
                Cache::forget($this->key($metric, $date));
            }
        }
    }

    private function windowSum(string $metric): int
    {
        $sum = 0;
        foreach ($this->windowDates() as $date) {
            $sum += (int) (Cache::get($this->key($metric, $date)) ?? 0);
        }

        return $sum;
    }

    /**
     * The day buckets that make up the current rolling window (today first).
     *
     * @return list<string>
     */
    private function windowDates(): array
    {
        $dates = [];
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $dates[] = now()->subDays($i)->toDateString();
        }

        return $dates;
    }

    private function bump(string $metric, int $by = 1): void
    {
        try {
            $store = Cache::getStore();
            if (method_exists($store, 'increment')) {
                $key = $this->key($metric, now()->toDateString());
                // Buckets outlive the window by a day so a snapshot never races expiry.
                Cache::add($key, 0, now()->addDays(self::WINDOW_DAYS + 1));
                Cache::increment($key, $by);
            }
        } catch (\Throwable) {
            // Telemetry must never break a broadcast or a subscription.
        }
    }

    private function key(string $metric, string $date): string
    {
        return "realtime:telemetry:{$metric}:{$date}";
    }
}
