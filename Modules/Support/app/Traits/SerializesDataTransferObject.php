<?php

namespace Modules\Support\Traits;

use JsonException;

trait SerializesDataTransferObject
{
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }
}
