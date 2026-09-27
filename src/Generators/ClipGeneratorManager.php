<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Generators;

use Hei\ScarlettPlayer\Contracts\ClipGenerator;
use Hei\ScarlettPlayer\Exceptions\ClipGenerationException;
use Illuminate\Support\Manager;

/**
 * Picks the clip generator named by `clips.generator`.
 *
 * A generator name is a key of clips.generators; its `driver` picks the implementation.
 * Add a driver with extend('fleet', fn ($app, array $config) => ...), which receives the
 * generator's config array.
 *
 * @method ClipGenerator driver(?string $driver = null)
 */
class ClipGeneratorManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('scarlett-player.clips.generator', 'local-ffmpeg');
    }

    /**
     * The config array of a generator under clips.generators.
     *
     * @return array<string, mixed>
     */
    public function generatorConfig(?string $name = null): array
    {
        $name ??= $this->getDefaultDriver();
        $config = $this->config->get("scarlett-player.clips.generators.{$name}");

        if (! is_array($config)) {
            throw ClipGenerationException::unknownDriver($name);
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * Render budget of a generator, in seconds.
     */
    public function timeout(?string $name = null): int
    {
        $timeout = $this->generatorConfig($name)['timeout'] ?? 300;

        return is_numeric($timeout) ? (int) $timeout : 300;
    }

    /**
     * Resolve a generator by name through its configured driver.
     *
     * @param  string  $driver  A key of clips.generators.
     */
    protected function createDriver($driver): ClipGenerator
    {
        $config = $this->generatorConfig($driver);
        $type = is_string($config['driver'] ?? null) ? $config['driver'] : $driver;

        if (isset($this->customCreators[$type])) {
            return $this->customCreators[$type]($this->container, $config);
        }

        return match ($type) {
            'local-ffmpeg' => $this->createLocalFfmpegDriver($config),
            default => throw ClipGenerationException::unknownDriver($type),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function createLocalFfmpegDriver(array $config): LocalFfmpegGenerator
    {
        return new LocalFfmpegGenerator(
            binary: is_string($config['binary'] ?? null) ? $config['binary'] : 'ffmpeg',
            timeout: is_numeric($config['timeout'] ?? null) ? (int) $config['timeout'] : 300,
        );
    }
}
