<?php

namespace Modules\Notification\Http\Requests\Api\V1;


class RunAnniversaryOfferRequest extends NotificationRequest
{
    public function rules(): array
    {
        return [
            'template' => ['nullable', 'string', 'max:120'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
