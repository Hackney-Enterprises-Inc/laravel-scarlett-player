<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The host user for the clip tests, with Sanctum tokens for the token recipe.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];
}
