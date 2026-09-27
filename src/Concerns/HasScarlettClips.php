<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Concerns;

use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Relations\StringKeyedMorphMany;
use Illuminate\Database\Eloquent\Model;

/**
 * For a host media model: the clips cut from it, and the id the player submits for it.
 * Pair it with the ScarlettMedia contract, whose toScarlettMediaSource() the model writes.
 *
 * @mixin Model
 */
trait HasScarlettClips
{
    /**
     * Clips cut from this media, through scarlett_clips.clippable_type / clippable_id.
     *
     * clippable_id is a string column so uuid and ulid keys fit; StringKeyedMorphMany
     * compares it with this model's key as text, because Postgres refuses
     * varchar = integer, including in has(), whereHas() and withCount().
     *
     * @return StringKeyedMorphMany<Clip, $this>
     */
    public function clips(): StringKeyedMorphMany
    {
        $instance = $this->newRelatedInstance(Clip::class);
        $table = $instance->getTable();

        return new StringKeyedMorphMany(
            $instance->newQuery(),
            $this,
            $table.'.clippable_type',
            $table.'.clippable_id',
            $this->getKeyName(),
        );
    }

    /**
     * The id the player submits for this model. Defaults to the route key.
     */
    public function scarlettMediaId(): string
    {
        return (string) $this->getRouteKey();
    }
}
