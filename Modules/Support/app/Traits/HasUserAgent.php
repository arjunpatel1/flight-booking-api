<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Jenssegers\Agent\Agent;
use Log;

/**
 * @property array $agent
 */
trait HasUserAgent
{
    /**
     * Get parsed user agent
     * @return Attribute
     */
    public function agent(): Attribute
    {
        return Attribute::make(get: function () {
            $userAgent = $this->getUserAgent();

            if ($userAgent) {
                $agent = tap(new Agent, function ($agent) use ($userAgent) {
                    $agent->setUserAgent($userAgent);
                });
                return [
                    'is_desktop' => $agent->isDesktop() ? true : (($agent->isMobile() || $agent->isTablet()) ? false : null),
                    'platform' => $agent->platform() ?: null,
                    'browser' => $agent->browser() ?: null,
                ];
            }
            return [
                'is_desktop' => null,
                'platform' => null,
                'browser' => null,
            ];
        });
    }

    /**
     * Get user agent
     * @return string|null
     */
    abstract function getUserAgent(): ?string;
}
