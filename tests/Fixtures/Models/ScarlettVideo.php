<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Models;

use Hei\ScarlettPlayer\Concerns\HasScarlettClips;
use Hei\ScarlettPlayer\Contracts\ScarlettMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Illuminate\Database\Eloquent\Model;

/**
 * A host model that owns its mapping through ScarlettMedia and HasScarlettClips.
 */
class ScarlettVideo extends Model implements ScarlettMedia
{
    use HasScarlettClips;

    protected $table = 'scarlett_videos';

    protected $guarded = [];

    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function toScarlettMediaSource(): MediaSource
    {
        return new MediaSource(
            id: $this->scarlettMediaId(),
            playbackUrl: 'https://cdn.example.test/signed/'.$this->getAttribute('uuid').'.m3u8',
            isLive: false,
            isProtected: true,
            duration: 42.0,
            title: 'From the model',
            model: $this,
            meta: ['route' => 'contract'],
        );
    }
}
