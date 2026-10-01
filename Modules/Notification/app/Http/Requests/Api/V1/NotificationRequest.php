<?php

namespace Modules\Notification\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

abstract class NotificationRequest extends Request
{
    protected function availableAttributes(): string
    {
        return 'notification::notifications.form_attributes';
    }
}
