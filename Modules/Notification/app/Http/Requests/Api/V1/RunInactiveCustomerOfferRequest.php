<?php

namespace Modules\Notification\Http\Requests\Api\V1;


class RunInactiveCustomerOfferRequest extends NotificationRequest
{
    public function rules(): array
    {
        return [
            'template' => ['nullable', 'string', 'max:120'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
            'valid_until' => ['nullable', 'date'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
