<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('v/probe', fn (): string => 'embed')->name('probe');
