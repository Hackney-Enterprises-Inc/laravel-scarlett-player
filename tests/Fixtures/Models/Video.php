<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A host model that does not implement ScarlettMedia: resolved through the attribute map.
 */
class Video extends Model
{
    protected $table = 'videos';

    protected $guarded = [];

    public $timestamps = false;
}
