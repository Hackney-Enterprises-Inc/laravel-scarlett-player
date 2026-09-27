<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Http\ClipRouteMiddleware;
use Hei\ScarlettPlayer\Http\Controllers\ClipController;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;

/*
 * The clips routes. Loaded by ScarlettPlayerServiceProvider only when
 * scarlett-player.routes.clips is on, inside a group that applies
 * routes.middleware.clips, the routes.prefix and the scarlett.clips. name prefix.
 *
 * The configured middleware guards creating a clip. The read-only routes drop its
 * auth and throttle entries and authorize themselves (see ClipRouteMiddleware).
 */
Route::post('clips', [ClipController::class, 'store'])->name('store');

Route::get('clips/{uuid}', [ClipController::class, 'show'])
    ->withoutMiddleware(ClipRouteMiddleware::createOnly())
    ->name('show');

Route::get('clips/{uuid}/play', [ClipController::class, 'play'])
    ->withoutMiddleware(ClipRouteMiddleware::createOnly())
    ->name('play');

Route::get('clips/{uuid}/preview', [ClipController::class, 'preview'])
    ->withoutMiddleware(ClipRouteMiddleware::createOnly())
    ->middleware(ValidateSignature::class)
    ->name('preview');
