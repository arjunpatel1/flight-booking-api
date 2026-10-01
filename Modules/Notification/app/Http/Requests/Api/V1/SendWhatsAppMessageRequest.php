<?php

namespace Modules\Notification\Http\Requests\Api\V1;

use Modules\Notification\Services\WhatsApp\TemplateValidator;
use Modules\Support\Exceptions\DomainException;

class SendWhatsAppMessageRequest extends NotificationRequest
{
    public function rules(): array
    {
        return [
            'recipient' => ['bail', 'required', 'string', 'max:30'],
            'template' => ['bail', 'required', 'string', 'max:120'],
            'parameters' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            try {
                $template = $this->string('template')->toString();
                $parameters = $this->input('parameters', []);

                TemplateValidator::validate($template, $parameters);
            } catch (DomainException $e) {
                $validator->errors()->add('template', $e->getMessage());
            }
        });
    }
}
