<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests;

use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** The beacon key every test app is configured with. Not a real key. */
    public const BEACON_KEY = 'test-beacon-key';

    /**
     * Config overrides applied after the defaults in defineEnvironment(), keyed by
     * dot path under `scarlett-player`. Set through withScarlettConfig(), which
     * reloads the application so the provider boots against them.
     *
     * @var array<string, mixed>
     */
    protected array $scarlettConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ScarlettPlayerServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['ScarlettPlayer' => ScarlettPlayer::class];
    }

    protected function defineEnvironment($app): void
    {
        // A fixed key, so encryption and signed URLs never depend on testbench's
        // skeleton .env, which a fresh CI install does not have.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('scarlett-player.beacons.key', self::BEACON_KEY);

        foreach ($this->scarlettConfig as $key => $value) {
            $app['config']->set("scarlett-player.{$key}", $value);
        }
    }

    /**
     * Rebuild the application with these `scarlett-player.*` overrides, so anything
     * the provider decides at register or boot time sees them.
     *
     * @param  array<string, mixed>  $overrides  dot paths under scarlett-player
     */
    protected function withScarlettConfig(array $overrides): void
    {
        $this->scarlettConfig = array_merge($this->scarlettConfig, $overrides);

        $this->reloadApplication();

        Http::preventStrayRequests();
    }

    /**
     * Load the package migrations into testbench's in-memory sqlite connection.
     */
    protected function usesMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function fixture(string $relative): string
    {
        $path = __DIR__.'/Fixtures/'.$relative;

        if (! is_file($path)) {
            throw new \RuntimeException("Missing fixture [{$path}].");
        }

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixtureJson(string $relative): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->fixture($relative), true, 512, JSON_THROW_ON_ERROR);
    }
}
