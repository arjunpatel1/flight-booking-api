<?php

namespace Modules\Pos\Services\Pos;

interface PosServiceInterface
{
    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function get(): array;

    /**
     * Get kitchen viewer data
     *
     * @return array
     */
    public function kitchenViewer(): array;
}
