<?php

namespace Modules\Core\Intelligence;

/**
 * The one shared context object for the NexDine Intelligence Layer.
 *
 * Every recommendation/prediction reads from this single context rather than
 * re-deriving time, shift, day, etc. on its own. Deterministic snapshot of
 * "right now" for a branch. Runtime signals (kitchen load, printer status,
 * queue depth, network) can be layered on via {@see withSignals()} without
 * changing the contract.
 */
final class RestaurantContext
{
    /**
     * @param array<string,mixed> $signals
     */
    public function __construct(
        public readonly ?int $branchId,
        public readonly string $time,
        public readonly int $hour,
        public readonly int $dayOfWeek,   // 1 (Mon) .. 7 (Sun)
        public readonly string $dayName,
        public readonly bool $isWeekend,
        public readonly string $shift,    // morning|lunch|dinner|night
        public readonly array $signals = [],
    ) {
    }

    /**
     * Return a copy with additional runtime signals merged in (kitchen load,
     * printer status, queue depth, network, …).
     *
     * @param array<string,mixed> $signals
     */
    public function withSignals(array $signals): self
    {
        return new self(
            branchId: $this->branchId,
            time: $this->time,
            hour: $this->hour,
            dayOfWeek: $this->dayOfWeek,
            dayName: $this->dayName,
            isWeekend: $this->isWeekend,
            shift: $this->shift,
            signals: [...$this->signals, ...$signals],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'branch_id' => $this->branchId,
            'time' => $this->time,
            'hour' => $this->hour,
            'day_of_week' => $this->dayOfWeek,
            'day_name' => $this->dayName,
            'is_weekend' => $this->isWeekend,
            'shift' => $this->shift,
            'signals' => $this->signals,
        ];
    }
}
