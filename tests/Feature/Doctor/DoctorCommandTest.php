<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\ScarlettVideo;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\PassingCheck;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ThrowingCheck;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\WarningCheck;
use Illuminate\Console\Command;

it('fails on a bare install, where no media model is configured', function (): void {
    $this->artisan('scarlett:doctor')
        ->expectsOutputToContain('media mapping')
        ->expectsOutputToContain('One or more checks failed.')
        ->assertExitCode(Command::FAILURE);
});

it('succeeds when checks only pass or warn, printing one row each', function (): void {
    app()->instance(CheckRegistry::class, (new CheckRegistry(app()))->add(PassingCheck::class, WarningCheck::class));

    $this->artisan('scarlett:doctor')
        ->expectsTable(['Check', 'Status', 'Detail'], [
            ['probe pass', 'PASS', 'probe passed'],
            ['probe warn', 'WARN', 'probe warned'],
        ])
        ->expectsOutputToContain('No check failed.')
        ->assertExitCode(Command::SUCCESS);
});

it('reports a check that throws as a failure instead of crashing', function (): void {
    $this->withScarlettConfig(['media.model' => ScarlettVideo::class]);
    app(CheckRegistry::class)->add(ThrowingCheck::class);

    $this->artisan('scarlett:doctor')
        ->expectsOutputToContain('probe exploded')
        ->assertExitCode(Command::FAILURE);
});
