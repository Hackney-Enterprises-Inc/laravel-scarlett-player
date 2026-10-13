<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests;

use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
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

    /**
     * Worker databases this process has already created, by name.
     *
     * @var array<string, true>
     */
    private static array $workerDatabases = [];

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

        $this->useWorkerDatabase($app);
    }

    /**
     * Under `pest --parallel` against MySQL or Postgres, point this worker at its own
     * database (DB_DATABASE suffixed with paratest's TEST_TOKEN), created on first use,
     * so two workers never migrate one schema at once. In-memory sqlite needs nothing:
     * each process already has its own.
     *
     * A DB_URL wins over the separate keys when Laravel connects, so the connection is
     * resolved through the same parser first and written back without its URL; otherwise
     * the URL's database would put every worker back on the shared one.
     *
     * @param  Application  $app
     */
    private function useWorkerDatabase($app): void
    {
        $token = getenv('TEST_TOKEN');

        if ($token === false || $token === '') {
            return;
        }

        $key = 'database.connections.'.$app['config']->get('database.default');

        /** @var array<string, mixed> $connection */
        $connection = (new ConfigurationUrlParser)->parseConfiguration((array) $app['config']->get($key));
        $driver = $connection['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $base = (string) ($connection['database'] ?? '');
        $name = "{$base}_{$token}";

        if (! isset(self::$workerDatabases[$name])) {
            $pdo = new \PDO(
                sprintf(
                    '%s:host=%s;port=%s;dbname=%s',
                    $driver === 'pgsql' ? 'pgsql' : 'mysql',
                    (string) ($connection['host'] ?? ''),
                    (string) ($connection['port'] ?? ''),
                    $base,
                ),
                (string) ($connection['username'] ?? ''),
                (string) ($connection['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            if ($driver === 'pgsql') {
                $exists = $pdo->prepare('select 1 from pg_database where datname = ?');
                $exists->execute([$name]);

                if ($exists->fetchColumn() === false) {
                    $pdo->exec('create database "'.$name.'"');
                }
            } else {
                $pdo->exec('create database if not exists `'.$name.'`');
            }

            self::$workerDatabases[$name] = true;
        }

        $app['config']->set($key, ['database' => $name] + $connection);
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
