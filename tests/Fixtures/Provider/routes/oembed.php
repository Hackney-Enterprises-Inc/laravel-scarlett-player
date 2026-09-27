<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('probe-oembed', fn (): string => 'oembed')->name('probe');
