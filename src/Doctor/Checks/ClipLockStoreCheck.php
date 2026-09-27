<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;

/**
 * Moderation and the object's visibility change together under a cache lock, and the
 * render job's unique lock lives in the cache too. The array store's locks are local to
 * one process and the null store's always succeed: both look like mutual exclusion and
 * give none. The file store works on one server only.
 */
class ClipLockStoreCheck implements Check
{
    public function name(): string
    {
        return 'Clip locks (cache store)';
    }

    public function run(): CheckResult
    {
        if (! config('scarlett-player.routes.clips') || ! config('scarlett-player.clips.enabled', true)) {
            return CheckResult::pass('Clips are off.');
        }

        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver", $store);

        return match ($driver) {
            'array', 'null' => CheckResult::warn("The default cache store [{$store}] uses the {$driver} driver, whose locks do not exclude other processes: concurrent moderation, renders and dispatches are not serialised. Use redis, database or memcached."),
            'file' => CheckResult::warn("The default cache store [{$store}] uses the file driver: its locks hold on one server only. With several app or worker servers, use redis, database or memcached."),
            default => CheckResult::pass("The default cache store [{$store}] ({$driver}) provides shared locks."),
        };
    }
}
