<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Every copy of an upgrade migration must sort after the migrations that create the
 * tables it alters, or a fresh `migrate` fails. The signals and reconnects upgrades
 * alter scarlett_views and scarlett_view_errors; the gauges upgrade only
 * scarlett_views, so it may sort before the errors create. A fixed 0001_01_01_00000{5,6} name
 * from 0.4.x/0.5.0 next to dated creates sorts first, and so does a dated copy
 * published in the same second as the full folder. The migrator orders every
 * registered path plus the default directory together by file name; so does this.
 */
class BeaconUpgradeMigrationOrderCheck implements Check
{
    private const VIEWS = '_create_scarlett_views_table';

    private const ERRORS = '_create_scarlett_view_errors_table';

    /** Upgrade name suffix => [publish tag, the create migrations it depends on]. */
    private const UPGRADES = [
        '_add_signals_to_scarlett_tables' => ['scarlett-migrations-signals', [self::VIEWS, self::ERRORS]],
        '_add_reconnects_to_scarlett_tables' => ['scarlett-migrations-reconnects', [self::VIEWS, self::ERRORS]],
        '_add_gauges_to_scarlett_tables' => ['scarlett-migrations-gauges', [self::VIEWS]],
    ];

    public function __construct(private readonly Container $container) {}

    public function name(): string
    {
        return 'beacon upgrade migration order';
    }

    public function run(): CheckResult
    {
        try {
            if (! $this->store() instanceof EloquentBeaconStore) {
                return CheckResult::pass('the bound beacon store does not use the package tables');
            }

            $migrator = $this->container->make('migrator');
            $names = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));
        } catch (Throwable $e) {
            return CheckResult::warn('could not list the registered migrations: '.$e->getMessage());
        }

        $creates = array_values(array_filter($names, fn (string $name): bool => $this->endsWithAny($name, [self::VIEWS, self::ERRORS])));

        if ($creates === []) {
            return CheckResult::pass('no scarlett create migration in the registered migration paths');
        }

        $misplaced = [];
        foreach ($names as $name) {
            foreach (self::UPGRADES as $suffix => [$tag, $requires]) {
                if (! str_ends_with($name, $suffix)) {
                    continue;
                }
                $later = array_filter($creates, fn (string $create): bool => $this->endsWithAny($create, $requires) && strcmp($name, $create) < 0);
                if ($later !== []) {
                    $misplaced[] = $name.'.php sorts before '.implode('.php and ', $later).'.php (--tag='.$tag.')';
                }
            }
        }

        if ($misplaced !== []) {
            return CheckResult::warn(implode('; ', $misplaced).'. A fresh migrate runs it before the table exists and stops. A database that already ran it is fine. Delete the copy if another copy sorts after the creates (a full scarlett-migrations publish from 0.4.0 contains the signals upgrade, from 0.5.0 the reconnects one); otherwise delete a fixed 0001_01_01_ name and publish its tag again, or rename a dated copy to sort after the create migrations.');
        }

        return CheckResult::pass('every scarlett upgrade migration sorts after the create migrations');
    }

    /** Resolve the host's actual binding, including custom and null stores. */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }

    /** @param  list<string>  $suffixes */
    private function endsWithAny(string $name, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
