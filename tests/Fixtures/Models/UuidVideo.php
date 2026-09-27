<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Models;

use Hei\ScarlettPlayer\Concerns\HasScarlettClips;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A host model keyed by uuid, the case a bigint clippable_id would break.
 */
class UuidVideo extends Model
{
    use HasScarlettClips, HasUuids;

    protected $table = 'uuid_videos';

    protected $guarded = [];

    public $timestamps = false;
}
