<?php

namespace Modules\Support\Contracts;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

interface DataTransferObject extends Arrayable, JsonSerializable
{
    public function toJson(int $options = 0): string;
}
