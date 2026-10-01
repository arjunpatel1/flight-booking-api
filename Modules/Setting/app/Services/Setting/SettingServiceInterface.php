<?php

namespace Modules\Setting\Services\Setting;

use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Models\Setting;

interface SettingServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return Setting
     */
    public function getModel(): Setting;

    /**
     * Get application settings
     *
     * @param bool $refresh
     * @param bool $includeLogoData
     * @return array
     */
    public function getAppSettings(bool $refresh = false, bool $includeLogoData = false): array;

    /**
     * Get settings
     *
     * @param SettingSection $section
     * @return array
     */
    public function getSettings(SettingSection $section): array;

    /**
     * Get meta
     *
     * @param SettingSection $section
     * @return array
     */
    public function getMeta(SettingSection $section): array;

    /**
     * Update Settings
     *
     * @param SettingSection $section
     * @param array $data
     * @return void
     */
    public function update(SettingSection $section, array $data): void;

    /**
     * Refresh setting binding
     */
    public function refreshSettingBinding(): void;
}
