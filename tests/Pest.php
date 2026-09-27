<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Tests\TestCase;
use Pest\Browser\Plugin;

/*
 * Four groups, one per test directory. `vendor/bin/pest` runs all four; the last two
 * skip themselves unless their environment is present, so a plain run on a machine
 * without Chromium or ffmpeg stays green and says why it skipped.
 */
/*
 * The browser plugin starts Playwright while it collects any test under tests/Browser/,
 * before a beforeEach can skip it, so a checkout without `npm install` could not run
 * the suite at all. Marking the plugin booted when the group is off stops that start;
 * the browser tests then skip below like any other.
 */
if (getenv('SCARLETT_BROWSER') !== '1') {
    Plugin::$booted = true;
}

pest()->extends(TestCase::class)->group('unit')->in('Unit');
pest()->extends(TestCase::class)->group('feature')->in('Feature');

pest()->extends(TestCase::class)->group('browser')
    ->beforeEach(function (): void {
        if (getenv('SCARLETT_BROWSER') !== '1') {
            $this->markTestSkipped('Browser group: set SCARLETT_BROWSER=1 to run it (needs Playwright Chromium and openssl).');
        }
    })
    ->in('Browser');

pest()->extends(TestCase::class)->group('integration')
    ->beforeEach(function (): void {
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            if (! binaryOnPath($binary)) {
                $this->markTestSkipped("Integration group: [{$binary}] is not on PATH.");
            }
        }
    })
    ->in('Integration');

/**
 * Whether an executable of this name is on PATH. Plain PHP, so the skip check needs
 * nothing beyond the test runner.
 */
function binaryOnPath(string $binary): bool
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory !== '' && is_executable($directory.DIRECTORY_SEPARATOR.$binary)) {
            return true;
        }
    }

    return false;
}
