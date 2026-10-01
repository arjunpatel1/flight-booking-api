<?php

namespace Modules\Order\Services\OrderFeedback;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Models\OrderFeedback;

interface OrderFeedbackServiceInterface
{
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function stats(array $filters = []): array;

    public function store(array $data): OrderFeedback;
}
