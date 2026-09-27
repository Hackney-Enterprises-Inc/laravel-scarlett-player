<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Http\Controllers\BeaconController;
use Hei\ScarlettPlayer\Http\Middleware\ScarlettApiKey;
use Illuminate\Support\Facades\Route;

/*
 * The beacons routes. Loaded by ScarlettPlayerServiceProvider only when
 * scarlett-player.routes.beacons is on, inside a group that applies
 * routes.middleware.beacons, the routes.prefix and the scarlett.beacons. name
 * prefix. The key middleware is added here rather than in that list, so a host
 * that overrides the list cannot drop authentication by accident.
 */
Route::post('beacons', BeaconController::class)
    ->middleware(ScarlettApiKey::class)
    ->name('store');
