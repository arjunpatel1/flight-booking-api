<?php

namespace Modules\Printer\Services\Diagnostics;

use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Models\PrintJob;

class PrintJobTrace
{
    public static function build(
        Order $order,
        PrintContentType $type,
        string $stage,
        string $message,
        array $context = [],
    ): array {
        $agentId = $context['agent_id'] ?? null;
        $printerId = $context['printer_id'] ?? null;

        return [
            'stage' => $stage,
            'message' => $message,
            'print_type' => $type->value,
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'specific_id' => $context['specific_id'] ?? null,
            'printer_id' => $printerId,
            'printer_name' => $context['printer_name'] ?? null,
            'agent_id' => $agentId,
            'route_source' => $context['route_source'] ?? null,
            'context' => array_filter($context, fn ($value) => ! is_null($value)),
            'timeline' => [
                self::timelineEntry($stage, $message, $context),
            ],
            'checks' => [
                [
                    'key' => 'order',
                    'label' => 'Order payload',
                    'status' => 'ok',
                    'message' => "Order #{$order->reference_no} resolved.",
                ],
                [
                    'key' => 'route',
                    'label' => 'Printer route',
                    'status' => $printerId ? 'ok' : 'failed',
                    'message' => $printerId
                        ? 'Printer route resolved.'
                        : 'No printer route matched assignment, register or branch fallback.',
                ],
                [
                    'key' => 'agent',
                    'label' => 'Agent target',
                    'status' => $agentId ? 'ok' : ($printerId ? 'warning' : 'failed'),
                    'message' => $agentId
                        ? "Target agent {$agentId} selected."
                        : 'No explicit online agent target was resolved.',
                ],
                [
                    'key' => 'queue',
                    'label' => 'Queue history',
                    'status' => $stage === 'queued' ? 'ok' : 'failed',
                    'message' => $message,
                ],
            ],
        ];
    }

    public static function appendToJob(
        PrintJob $job,
        string $stage,
        string $message,
        array $context = [],
    ): void {
        $config = (array) $job->printer_config;
        $config = self::appendToConfig($config, $stage, $message, $context);

        $job->forceFill(['printer_config' => $config])->saveQuietly();
    }

    public static function appendToConfig(
        array $config,
        string $stage,
        string $message,
        array $context = [],
    ): array {
        $diagnostics = (array) data_get($config, 'diagnostics', []);
        $timeline = data_get($diagnostics, 'timeline', []);
        if (! is_array($timeline)) {
            $timeline = [];
        }

        $timeline[] = self::timelineEntry($stage, $message, $context);

        data_set($diagnostics, 'stage', $stage);
        data_set($diagnostics, 'message', $message);
        data_set($diagnostics, 'timeline', array_slice($timeline, -20));
        data_set(
            $diagnostics,
            'context',
            array_filter([
                ...(array) data_get($diagnostics, 'context', []),
                ...$context,
            ], fn ($value) => ! is_null($value)),
        );

        data_set($config, 'diagnostics', self::withUpdatedChecks($diagnostics, $stage, $message, $context));

        return $config;
    }

    private static function withUpdatedChecks(
        array $diagnostics,
        string $stage,
        string $message,
        array $context,
    ): array {
        $checks = data_get($diagnostics, 'checks', []);
        if (! is_array($checks)) {
            $checks = [];
        }

        $updates = match ($stage) {
            'claimed' => [
                'agent' => [
                    'status' => 'ok',
                    'message' => 'Agent '.($context['agent_id'] ?? 'unknown').' picked up this job.',
                ],
                'queue' => [
                    'status' => 'ok',
                    'message' => 'Print job was leased to the agent.',
                ],
            ],
            'retry_queued' => [
                'queue' => [
                    'status' => 'warning',
                    'message' => 'Job was manually retried and returned to queue.',
                ],
            ],
            'success' => [
                'queue' => [
                    'status' => 'ok',
                    'message' => 'Agent completed the print job.',
                ],
                'delivery' => [
                    'label' => 'Agent delivery',
                    'status' => 'ok',
                    'message' => $message,
                ],
            ],
            'failed' => [
                'queue' => [
                    'status' => 'failed',
                    'message' => $message,
                ],
                'delivery' => [
                    'label' => 'Agent delivery',
                    'status' => 'failed',
                    'message' => $message,
                ],
            ],
            default => [
                'queue' => [
                    'status' => str_contains($stage, 'failed') ? 'failed' : 'warning',
                    'message' => $message,
                ],
            ],
        };

        foreach ($updates as $key => $update) {
            $checks = self::upsertCheck($checks, $key, $update);
        }

        data_set($diagnostics, 'checks', array_values($checks));

        return $diagnostics;
    }

    private static function upsertCheck(array $checks, string $key, array $update): array
    {
        foreach ($checks as $index => $check) {
            if (($check['key'] ?? null) === $key) {
                $checks[$index] = [
                    ...$check,
                    ...$update,
                    'key' => $key,
                ];

                return $checks;
            }
        }

        $checks[] = [
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            ...$update,
        ];

        return $checks;
    }

    private static function timelineEntry(string $stage, string $message, array $context = []): array
    {
        return [
            'stage' => $stage,
            'message' => $message,
            'at' => now()->toISOString(),
            'context' => array_filter($context, fn ($value) => ! is_null($value)),
        ];
    }
}
