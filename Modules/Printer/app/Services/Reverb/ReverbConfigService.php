<?php

namespace Modules\Printer\Services\Reverb;

use Illuminate\Http\Request;

class ReverbConfigService
{
    public const DEFAULT_PROTOCOL = 7;
    public const DEFAULT_CLIENT = 'nexdine-print-agent';
    public const DEFAULT_VERSION = '1.0.0';
    public const DEFAULT_FLASH = 'false';

    private string $key;
    private array $options;

    public function __construct()
    {
        $this->key = (string) config('broadcasting.connections.reverb.key', '');
        $this->options = (array) config('broadcasting.connections.reverb.options', []);
    }

    public function getAppKey(): string
    {
        return $this->key;
    }

    public function getSocketUrl(Request $request): string
    {
        $host = $this->resolveHost($request);
        $port = (int) ($this->options['port'] ?? 443);
        $scheme = (string) ($this->options['scheme'] ?? 'https');
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';
        $portSuffix = in_array($port, [80, 443], true) ? '' : ":{$port}";
        $path = trim((string) ($this->options['path'] ?? ''), '/');
        $pathPrefix = $path !== '' ? "/{$path}" : '';
        $protocol = (int) ($this->options['protocol'] ?? self::DEFAULT_PROTOCOL);
        $client = (string) ($this->options['client'] ?? self::DEFAULT_CLIENT);
        $version = (string) ($this->options['version'] ?? self::DEFAULT_VERSION);
        $flash = (string) ($this->options['flash'] ?? self::DEFAULT_FLASH);

        return $this->key !== ''
            ? "{$wsScheme}://{$host}{$portSuffix}{$pathPrefix}/app/{$this->key}?protocol={$protocol}&client={$client}&version={$version}&flash={$flash}"
            : '';
    }

    public function toArray(Request $request): array
    {
        return [
            'app_key' => $this->getAppKey(),
            'socket_url' => $this->getSocketUrl($request),
        ];
    }

    private function resolveHost(Request $request): string
    {
        $configuredHost = $this->normalizeHost((string) ($this->options['host'] ?? ''));
        if ($configuredHost !== '') {
            return $configuredHost;
        }

        $appUrlHost = $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appUrlHost !== '') {
            return $appUrlHost;
        }

        return $request->getHost();
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);

        if ($host === '') {
            return '';
        }

        return (string) (parse_url($host, PHP_URL_HOST) ?: $host);
    }
}
