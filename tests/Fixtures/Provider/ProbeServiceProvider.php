<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Provider;

use Closure;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The real provider with its route files swapped for probes that each register one
 * `probe` route, and one extra doctor check and schedule entry, so the tests can see
 * what the shared loading applies without any module having shipped a route.
 */
class ProbeServiceProvider extends ScarlettPlayerServiceProvider
{
    protected function routePath(string $file): string
    {
        return __DIR__.'/routes/'.$file;
    }

    /**
     * @return list<class-string<Check>>
     */
    protected function doctorChecks(): array
    {
        return [...parent::doctorChecks(), PassingCheck::class];
    }

    /**
     * @return list<Closure(Schedule): void>
     */
    protected function schedule(): array
    {
        return [
            ...parent::schedule(),
            fn (Schedule $schedule) => $schedule->command('scarlett:doctor')->daily(),
        ];
    }
}
