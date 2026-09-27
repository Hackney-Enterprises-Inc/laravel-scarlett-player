<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Facades;

use Hei\ScarlettPlayer\ScarlettPlayer as ScarlettPlayerRoot;
use Hei\ScarlettPlayer\Testing\FakeScarlett;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Hei\ScarlettPlayer\Player\PlayerConfigBuilder for(\Illuminate\Database\Eloquent\Model|string|\Hei\ScarlettPlayer\Data\MediaSource $media)
 * @method static string embedUrl(\Illuminate\Database\Eloquent\Model|string|\Hei\ScarlettPlayer\Data\MediaSource $media, \DateTimeInterface|\DateInterval|int|null $expires = null)
 * @method static string embedCode(\Illuminate\Database\Eloquent\Model|string|\Hei\ScarlettPlayer\Data\MediaSource $media, \DateTimeInterface|\DateInterval|int|null $expires = null, int|string $width = 640, int|string $height = 360)
 * @method static \Hei\ScarlettPlayer\Data\MediaSource resolve(string $mediaId)
 *
 * @see ScarlettPlayerRoot
 */
class ScarlettPlayer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ScarlettPlayerRoot::class;
    }

    /**
     * Swap the facade root for a fake that records what the app resolved, and the
     * beacons and clip requests it saw, so a consuming app can assert on them.
     *
     * A real static method rather than an `@method` tag: a facade forwards to its
     * root, and this replaces the root instead.
     */
    public static function fake(): FakeScarlett
    {
        $fake = app(FakeScarlett::class);

        static::swap($fake);

        return $fake;
    }
}
