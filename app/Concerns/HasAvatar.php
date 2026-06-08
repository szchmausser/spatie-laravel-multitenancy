<?php

namespace App\Concerns;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Shared avatar accessor for User and Landlord models.
 *
 * Both models define an identical `getAvatarAttribute()` that
 * returns the URL of the first 'avatar' media item. This trait
 * eliminates that duplication.
 *
 * NOTE: `registerMediaCollections()` cannot be shared here because
 * `InteractsWithMedia` already defines it and PHP traits don't
 * support method overriding. Each model keeps its own
 * `registerMediaCollections()` with the identical avatar config.
 */
trait HasAvatar
{
    /**
     * Get the URL of the first avatar media item.
     */
    protected function getAvatarAttribute(): ?string
    {
        /** @var Media|null $media */
        $media = $this->getFirstMedia('avatar');

        return $media?->getUrl();
    }
}
