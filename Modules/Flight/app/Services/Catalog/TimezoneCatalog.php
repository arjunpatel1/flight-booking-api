<?php

namespace Modules\Flight\Services\Catalog;

use DateTimeZone;
use IntlTimeZone;

/**
 * IANA time zones straight from PHP's time zone database, so the airport
 * form never needs a code change when zones are added or renamed: updating
 * PHP (or the timezonedb extension) updates the list. Retired names such as
 * Asia/Calcutta are translated to their current name (Asia/Kolkata).
 */
class TimezoneCatalog
{
    /** @var list<string>|null */
    private ?array $zones = null;

    /** @return list<string> current zone names, plus UTC */
    public function all(): array
    {
        return $this->zones ??= DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    }

    /** @return array<string, list<string>> ISO country code => its zones, e.g. IN => [Asia/Kolkata] */
    public function byCountry(): array
    {
        $map = [];
        foreach ($this->all() as $zone) {
            $country = (new DateTimeZone($zone))->getLocation()['country_code'] ?? '??';
            if ($country !== '??') $map[$country][] = $zone;
        }
        ksort($map);

        return $map;
    }

    /** The current name for a zone; unknown or already-current names come back unchanged. */
    public function current(string $zone): string
    {
        if (in_array($zone, $this->all(), true) || ! method_exists(IntlTimeZone::class, 'getIanaID')) {
            return $zone;
        }

        $iana = IntlTimeZone::getIanaID($zone);

        return is_string($iana) && in_array($iana, $this->all(), true) ? $iana : $zone;
    }

    public function version(): string
    {
        return timezone_version_get();
    }
}
