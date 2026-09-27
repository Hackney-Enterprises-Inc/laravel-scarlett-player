<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Http\Controllers\OEmbedController;
use Illuminate\Support\Facades\Route;

/*
 * The oEmbed endpoint. Loaded by ScarlettPlayerServiceProvider only when
 * scarlett-player.routes.embed is on, inside a group that applies
 * routes.middleware.oembed, the routes.prefix and the scarlett.oembed. name prefix.
 */
Route::get('oembed', [OEmbedController::class, 'show'])->name('show');
