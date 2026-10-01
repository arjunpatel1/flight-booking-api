<?php

namespace Modules\Notification\Services\WhatsApp;

use Modules\Support\Exceptions\DomainException;

class TemplateValidator
{
    public static function validate(string $templateId, array $parameters, ?array $variables = null): bool
    {
        $templates = collect(setting('whatsapp_templates') ?: [])
            ->map(function ($template) {
                if (is_string($template)) {
                    return [
                        'id' => $template,
                        'template_id' => $template,
                        'variables' => [],
                    ];
                }
                return [
                    'id' => $template['id'] ?? $template['name'] ?? null,
                    'template_id' => $template['template_id'] ?? $template['id'] ?? $template['name'] ?? null,
                    'variables' => $template['variables'] ?? [],
                ];
            })
            ->flatMap(fn (array $template) => array_fill_keys(array_unique(array_filter([
                $template['id'], $template['template_id'],
            ])), $template));

        $template = $templates->get($templateId);

        if (!$template) {
            throw new DomainException("WhatsApp template '{$templateId}' not found");
        }

        $requiredVariables = array_values(array_diff($template['variables'] ?? [], $variables ?? []));

        if (empty($requiredVariables)) {
            return true;
        }

        // Check if it's a numeric array (positional parameters)
        if (is_numeric(array_key_first($parameters))) {
            if (count($parameters) < count($requiredVariables)) {
                throw new DomainException(
                    "Template '{$templateId}' requires " . count($requiredVariables) . " parameter(s), " .
                    count($parameters) . " provided"
                );
            }
            return true;
        }

        // Check if it's a named array (key-value parameters)
        foreach ($requiredVariables as $variable) {
            if (!isset($parameters[$variable])) {
                throw new DomainException(
                    "Template '{$templateId}' requires parameter '{$variable}'"
                );
            }
        }

        return true;
    }

    public static function getTemplate(string $templateId): ?array
    {
        return collect(setting('whatsapp_templates') ?: [])
            ->map(function ($template) {
                if (is_string($template)) {
                    return [
                        'id' => $template,
                        'template_id' => $template,
                        'name' => $template,
                        'variables' => [],
                    ];
                }
                return [
                    'id' => $template['id'] ?? $template['name'] ?? null,
                    'template_id' => $template['template_id'] ?? $template['id'] ?? $template['name'] ?? null,
                    'name' => $template['name'] ?? $template['id'] ?? null,
                    'variables' => $template['variables'] ?? [],
                    'category' => $template['category'] ?? null,
                ];
            })
            ->first(fn (array $template) => in_array($templateId, [$template['id'], $template['template_id']], true));
    }
}
