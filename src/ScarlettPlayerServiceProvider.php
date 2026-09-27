<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer;

use Closure;
use Hei\ScarlettPlayer\Clips\ClipUrlIssuer;
use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Commands\BeaconTestCommand;
use Hei\ScarlettPlayer\Commands\DoctorCommand;
use Hei\ScarlettPlayer\Commands\PruneViewsCommand;
use Hei\ScarlettPlayer\Commands\ReconcileClipsCommand;
use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconIpColumnCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconKeyCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconQueueCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconRouteCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconStoreCheck;
use Hei\ScarlettPlayer\Doctor\Checks\ClipDiskCheck;
use Hei\ScarlettPlayer\Doctor\Checks\ClipLockStoreCheck;
use Hei\ScarlettPlayer\Doctor\Checks\CorsCheck;
use Hei\ScarlettPlayer\Doctor\Checks\EmbedBundleCheck;
use Hei\ScarlettPlayer\Doctor\Checks\EmbedDomainsCheck;
use Hei\ScarlettPlayer\Doctor\Checks\FfmpegCheck;
use Hei\ScarlettPlayer\Doctor\Checks\MediaMappingCheck;
use Hei\ScarlettPlayer\Doctor\Checks\QueueConnectionCheck;
use Hei\ScarlettPlayer\Doctor\Checks\QueueTimeoutCheck;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconStoreException;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Http\Middleware\ExpectsJson;
use Hei\ScarlettPlayer\Media\ConfigModelResolver;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Hei\ScarlettPlayer\Policies\ClipPolicy;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Hei\ScarlettPlayer\Testing\FakeBeaconStore;
use Hei\ScarlettPlayer\Testing\FakeScarlett;
use Hei\ScarlettPlayer\View\Components\ScarlettPlayer as ScarlettPlayerComponent;
use Illuminate\Auth\Access\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Cut by module so each round of work owns its methods outright: the shared
 * register() and boot() call register<Module>() and boot<Module>(), and the doctor
 * checks and schedule are assembled from <module>DoctorChecks() and <module>Schedule().
 * A module edits only the methods named for it.
 */
class ScarlettPlayerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scarlett-player.php', 'scarlett-player');

        $this->app->singleton(ResolvesMedia::class, function (Application $app): ResolvesMedia {
            $class = $app->make(Repository::class)->get('scarlett-player.media.resolver') ?? ConfigModelResolver::class;

            return $app->make($class);
        });

        $this->app->singleton(ScarlettPlayer::class);
        $this->app->alias(ScarlettPlayer::class, 'scarlett-player');

        $this->app->singleton(CheckRegistry::class);

        $this->registerBeacons();
        $this->registerClips();
        $this->registerPlayer();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/scarlett-player.php' => config_path('scarlett-player.php'),
        ], 'scarlett-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'scarlett-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/scarlett'),
        ], 'scarlett-views');

        $this->publishes([
            __DIR__.'/../resources/js/init.js' => resource_path('js/vendor/scarlett-player/init.js'),
        ], 'scarlett-js');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'scarlett');
        Blade::componentNamespace('Hei\\ScarlettPlayer\\View\\Components', 'scarlett');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
            ]);
        }

        $this->callAfterResolving(CheckRegistry::class, function (CheckRegistry $registry): void {
            $registry->add(...$this->doctorChecks());
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            foreach ($this->schedule() as $entry) {
                $entry($schedule);
            }
        });

        $this->bootBeacons();
        $this->bootClips();
        $this->bootPlayer();
    }

    /**
     * Every doctor check, shared first, then per module in order.
     *
     * @return list<class-string<Check>>
     */
    protected function doctorChecks(): array
    {
        return [
            MediaMappingCheck::class,
            QueueConnectionCheck::class,
            ...$this->beaconsDoctorChecks(),
            ...$this->clipsDoctorChecks(),
            ...$this->playerDoctorChecks(),
        ];
    }

    /**
     * Every schedule entry, per module in order. Each entry receives the Schedule.
     *
     * @return list<Closure(Schedule): void>
     */
    protected function schedule(): array
    {
        return [
            ...$this->beaconsSchedule(),
            ...$this->clipsSchedule(),
            ...$this->playerSchedule(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Beacons
    |--------------------------------------------------------------------------
    */

    // The beacons module.
    private function registerBeacons(): void
    {
        $this->app->singleton(BeaconStore::class, function (Application $app): BeaconStore {
            $store = $app->make(Repository::class)->get('scarlett-player.beacons.store') ?? 'eloquent';
            $class = match ($store) {
                'eloquent' => EloquentBeaconStore::class,
                'null' => NullBeaconStore::class,
                default => is_string($store) ? $store : '',
            };

            if (! class_exists($class) || ! is_subclass_of($class, BeaconStore::class)) {
                throw InvalidBeaconStoreException::unknown(is_string($store) ? $store : get_debug_type($store));
            }

            return $app->make($class);
        });

        // ScarlettPlayer::fake() resolves a FakeScarlett: from then on beacons go to a
        // FakeBeaconStore that also fills the fake's beacon ledger, so a consuming
        // app's assertBeaconRecorded() sees beacons posted through the route.
        $this->app->afterResolving(FakeScarlett::class, function (FakeScarlett $fake, Application $app): void {
            $app->instance(BeaconStore::class, new FakeBeaconStore(
                fn (BeaconPayload $payload) => $fake->recordBeacon($payload->toArray()),
            ));
        });
    }

    // The beacons module.
    private function bootBeacons(): void
    {
        RateLimiter::for('scarlett-beacons', fn (Request $request): Limit => $this->limitFrom(
            config('scarlett-player.beacons.throttle'),
        )->by((string) $request->ip()));

        if (config('scarlett-player.routes.beacons')) {
            $this->loadModuleRoutes('beacons');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                BeaconTestCommand::class,
                PruneViewsCommand::class,
            ]);
        }
    }

    /**
     * The beacons module.
     *
     * @return list<class-string<Check>>
     */
    private function beaconsDoctorChecks(): array
    {
        return [
            BeaconKeyCheck::class,
            BeaconStoreCheck::class,
            BeaconRouteCheck::class,
            CorsCheck::class,
            BeaconQueueCheck::class,
            BeaconIpColumnCheck::class,
        ];
    }

    /**
     * The beacons module.
     *
     * @return list<Closure(Schedule): void>
     */
    private function beaconsSchedule(): array
    {
        if (! config('scarlett-player.beacons.schedule_prune')) {
            return [];
        }

        return [
            function (Schedule $schedule): void {
                $schedule->command(PruneViewsCommand::class)->daily();
            },
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Clips
    |--------------------------------------------------------------------------
    */

    // The clips module.
    private function registerClips(): void
    {
        $this->app->singleton(ClipGeneratorManager::class);
        $this->app->singleton(ClipVerifier::class);
        $this->app->singleton(ClipUrlIssuer::class);
    }

    // The clips module.
    private function bootClips(): void
    {
        RateLimiter::for('scarlett-clips', fn (Request $request): Limit => $this->limitFrom(
            config('scarlett-player.clips.throttle'),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The package default fails closed. A host policy registered by an app provider
        // (booted after this one) replaces it.
        /** @var Gate $gate the framework binds the concrete gate to the contract */
        $gate = $this->app->make(GateContract::class);

        if (! array_key_exists(Clip::class, $gate->policies())) {
            $gate->policy(Clip::class, ClipPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileClipsCommand::class]);
        }

        if (config('scarlett-player.routes.clips')) {
            // ExpectsJson runs ahead of the configured middleware: the plugin sends no
            // Accept header, and an auth or validation failure must answer JSON.
            Route::middleware(ExpectsJson::class)
                ->group(fn () => $this->loadModuleRoutes('clips'));
        }
    }

    /**
     * The clips module.
     *
     * @return list<class-string<Check>>
     */
    private function clipsDoctorChecks(): array
    {
        return [
            FfmpegCheck::class,
            ClipDiskCheck::class,
            QueueTimeoutCheck::class,
            ClipLockStoreCheck::class,
        ];
    }

    /**
     * The clips module.
     *
     * @return list<Closure(Schedule): void>
     */
    private function clipsSchedule(): array
    {
        if (! config('scarlett-player.clips.schedule_reconcile', true)) {
            return [];
        }

        return [
            function (Schedule $schedule): void {
                $schedule->command('scarlett:clips:reconcile')->everyMinute()->withoutOverlapping();
            },
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Player and embed
    |--------------------------------------------------------------------------
    */

    // The player and embed module.
    private function registerPlayer(): void
    {
        $this->app->singleton(EmbedUrlGenerator::class);

        $this->app->bind(
            PlayerConfigBuilder::class,
            fn (Application $app, array $parameters): PlayerConfigBuilder => new PlayerConfigBuilder(
                $this->mediaParameter($parameters),
                $app->make(Repository::class),
                $app->make(UrlGenerator::class),
                $app->make(Router::class),
                $app->make(GateContract::class),
                $app->make(EmbedUrlGenerator::class),
            ),
        );

        // <x-scarlett-player>, as the plan writes it, beside the scarlett:: namespace.
        $this->callAfterResolving(BladeCompiler::class, function (BladeCompiler $blade): void {
            $blade->component(ScarlettPlayerComponent::class, 'scarlett-player');
            // The scarlett:: class namespace would look for View\Components\Player and
            // then fall back to the bare view; alias it to the real component instead.
            $blade->component(ScarlettPlayerComponent::class, 'scarlett::player');
        });
    }

    // The player and embed module.
    private function bootPlayer(): void
    {
        if (config('scarlett-player.routes.embed')) {
            // The embed page lives at embed.route, outside the prefix.
            $this->loadModuleRoutes('embed', prefixed: false);
            $this->loadModuleRoutes('oembed');
        }
    }

    /**
     * The player and embed module.
     *
     * @return list<class-string<Check>>
     */
    private function playerDoctorChecks(): array
    {
        return [
            EmbedBundleCheck::class,
            EmbedDomainsCheck::class,
        ];
    }

    /**
     * The player and embed module.
     *
     * @return list<Closure(Schedule): void>
     */
    private function playerSchedule(): array
    {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Load routes/<module>.php with the module's middleware from routes.middleware,
     * the route prefix unless told otherwise, and the scarlett.<module>. name prefix.
     */
    protected function loadModuleRoutes(string $module, bool $prefixed = true): void
    {
        $route = Route::middleware((array) config("scarlett-player.routes.middleware.{$module}", []))
            ->name("scarlett.{$module}.");

        if ($prefixed) {
            $route->prefix((string) config('scarlett-player.routes.prefix'));
        }

        $route->group(fn () => $this->loadRoutesFrom($this->routePath("{$module}.php")));
    }

    /**
     * Absolute path of a package route file.
     */
    protected function routePath(string $file): string
    {
        return __DIR__.'/../routes/'.$file;
    }

    /**
     * A rate limit from an "attempts,minutes" string. Minutes default to 1.
     */
    protected function limitFrom(mixed $throttle): Limit
    {
        [$attempts, $minutes] = array_pad(explode(',', (string) $throttle, 2), 2, '1');

        return Limit::perMinutes(max(1, (int) $minutes), max(1, (int) $attempts));
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    private function mediaParameter(array $parameters): MediaSource
    {
        $media = $parameters['media'] ?? null;

        if (! $media instanceof MediaSource) {
            throw new \InvalidArgumentException('PlayerConfigBuilder is built with a [media] MediaSource parameter.');
        }

        return $media;
    }
}
