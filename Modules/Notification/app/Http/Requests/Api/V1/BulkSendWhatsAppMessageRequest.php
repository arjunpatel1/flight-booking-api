<?php

namespace Modules\Notification\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Modules\Notification\Services\WhatsApp\TemplateValidator;
use Modules\Support\Exceptions\DomainException;

class BulkSendWhatsAppMessageRequest extends NotificationRequest
{
    public function rules(): array
    {
        return [
            'audience' => ['bail', 'required', Rule::in(BulkSendWhatsAppMessageJob::audienceKeys())],
            'role_names' => ['required_if:audience,roles', 'nullable', 'array'],
            'role_names.*' => ['string', 'exists:roles,name'],
            'recipient_ids' => ['nullable', 'array'],
            'recipient_ids.*' => ['integer', 'exists:users,id'],
            'template' => ['bail', 'required', 'string', 'max:120'],
            'parameters' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            try {
                $template = $this->string('template')->toString();
                $parameters = $this->input('parameters', []);

                TemplateValidator::validate($template, $parameters, BulkSendWhatsAppMessageJob::DYNAMIC_PARAMETERS);
            } catch (DomainException $e) {
                $validator->errors()->add('template', $e->getMessage());
            }
        });
    }
}
