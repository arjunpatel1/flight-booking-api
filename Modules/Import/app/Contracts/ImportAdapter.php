<?php

namespace Modules\Import\Contracts;

interface ImportAdapter
{
    public function import(array $row, array $options = []): void;
}
