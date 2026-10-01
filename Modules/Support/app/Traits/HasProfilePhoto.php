<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * @property string|null $profile_photo_url
 */
trait HasProfilePhoto
{
    /**
     * Get the URL to the user's profile photo.
     *
     * @return Attribute
     */
    public function profilePhotoUrl(): Attribute
    {
        return Attribute::make(get: fn() => $this->defaultProfilePhotoUrl(
            $this->defaultProfilePhotoColor(),
            $this->defaultProfilePhotoBackgroundColor()
        ));
    }

    /**
     * Get the default profile photo URL if no profile photo has been uploaded.
     *
     * @param string $color
     * @param string $background
     * @return string
     */
    public function defaultProfilePhotoUrl(string $color = "FFFFFF", string $background = "F57C00"): string
    {
        $name = trim(collect(explode(' ', $this->profilePhotoName()))->map(fn($segment) => mb_substr($segment, 0, 1))->join(' '));

        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . "&color=$color&background=$background";
    }

    /**
     * Define the attribute the holds the model name
     *
     * @return string
     */
    public function profilePhotoName(): string
    {
        return $this->name;
    }

    /**
     * Default profile photo color
     *
     * @return string
     */
    public function defaultProfilePhotoColor(): string
    {
        return "FFFFFF";
    }

    /**
     * Default profile photo background color
     *
     * @return string
     */
    public function defaultProfilePhotoBackgroundColor(): string
    {
        return "F57C00";
    }
}
