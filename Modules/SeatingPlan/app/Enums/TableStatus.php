<?php

namespace Modules\SeatingPlan\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum TableStatus: string
{
    use EnumArrayable, EnumTranslatable;
    
    case Available = "available";
    case Occupied = "occupied";
    case Reserved = "reserved";
    case Cleaning = "cleaning";
    case Merged = "merged";

    /**
     * Get enum Arrayable as translation
     *
     * @param array $except
     * @return array
     */
    public static function toArrayTrans(array $except = []): array
    {
        $cases = [];

        foreach (self::values() as $value) {
            if (in_array($value, $except)) {
                continue;
            }
            $status = TableStatus::from($value);
            $cases[] = [
                "id" => $value,
                "name" => __(TableStatus::getTransKey() . ".$value"),
                "color" => $status->color(),
            ];
        }

        return $cases;
    }

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "seatingplan::enums.table_statuses";
    }

    /**
     * Get color for direction
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::Available => "#4CAF50",
            self::Occupied => "#F44336",
            self::Reserved => "#FFC107",
            self::Cleaning => "#2196F3",
            self::Merged => "#d35400",
        };
    }

    /**
     * Get enum as array value with trans
     *
     * @return array
     */
    public function toTrans(): array
    {
        return [
            "id" => $this->value,
            "name" => $this->trans(),
            "color" => $this->color()
        ];
    }
}
