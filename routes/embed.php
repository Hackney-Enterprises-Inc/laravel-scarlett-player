<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Http\Controllers\EmbedController;
use Hei\ScarlettPlayer\Http\Middleware\ValidateEmbedSignature;
use Illuminate\Support\Facades\Route;

/*
 * The embed page. Loaded by ScarlettPlayerServiceProvider only when
 * scarlett-player.routes.embed is on, inside a group that applies
 * routes.middleware.embed and the scarlett.embed. name prefix, with no URI prefix:
 * the page lives at embed.route. The first route parameter is the media id.
 */
Route::get((string) config('scarlett-player.embed.route', '/v/{uuid}'), [EmbedController::class, 'show'])
    ->middleware(ValidateEmbedSignature::class)
    ->name('show');
