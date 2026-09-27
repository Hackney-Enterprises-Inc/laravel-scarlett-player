<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('probe-beacons', fn (): string => 'beacons')->name('probe');
