<?php

namespace Modules\Saas\Services\Onboarding;

use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;

/**
 * Countries and their states / provinces for address forms, from the
 * commerceguys/addressing dataset (CLDR + Google address data). Lists stay
 * current with `composer update`; nothing is hardcoded here.
 */
class GeoCatalog
{
    public function __construct(
        private readonly CountryRepository $countries = new CountryRepository(),
        private readonly SubdivisionRepository $subdivisions = new SubdivisionRepository(),
    ) {}

    /** @return list<array{code: string, name: string}> sorted by name */
    public function countries(string $locale = 'en'): array
    {
        $list = $this->countries->getList($locale);
        asort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn (string $code, string $name) => ['code' => $code, 'name' => $name], array_keys($list), $list);
    }

    /**
     * States / provinces of a country; empty when the country has none in the
     * dataset (e.g. Kuwait), in which case forms accept free text.
     *
     * @return list<array{code: string, name: string}> sorted by name
     */
    public function states(string $countryCode, string $locale = 'en'): array
    {
        $list = $this->subdivisions->getList([strtoupper($countryCode)], $locale);
        asort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn (string $code, string $name) => ['code' => $code, 'name' => $name], array_keys($list), $list);
    }
}
