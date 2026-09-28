<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Feature\Clips\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToSetVisibility;

/**
 * The faked clip disk rebuilt as Laravel's real FilesystemAdapter with throw => false,
 * over a Flysystem adapter that refuses chosen visibility writes with
 * UnableToSetVisibility. Laravel then answers setVisibility() with false, exactly as a
 * production disk configured throw => false does when the storage call fails.
 */
final class RefusingVisibilityDisk implements FlysystemAdapter
{
    /** @var list<string> Visibilities whose write is refused ('public', 'private'). */
    public array $refuse = [];

    /** @var (\Closure(string, string): void)|null Runs once, before the next visibility write. */
    public ?\Closure $before = null;

    /** @var list<array{string, string}> Refused writes, as [path, visibility]. */
    public array $refused = [];

    private function __construct(private readonly FlysystemAdapter $inner) {}

    public static function install(string $name = 'clips'): self
    {
        $fake = Storage::disk($name);

        if (! $fake instanceof FilesystemAdapter) {
            throw new \LogicException("Disk [{$name}] is not a FilesystemAdapter.");
        }

        $adapter = new self($fake->getAdapter());
        $config = array_merge($fake->getConfig(), ['throw' => false, 'report' => false]);

        Storage::set($name, new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config));

        return $adapter;
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if ($this->before !== null) {
            $hook = $this->before;
            $this->before = null;
            $hook($path, $visibility);
        }

        if (in_array($visibility, $this->refuse, true)) {
            $this->refused[] = [$path, $visibility];

            throw UnableToSetVisibility::atLocation($path, 'refused by the test disk');
        }

        $this->inner->setVisibility($path, $visibility);
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->inner->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->inner->writeStream($path, $contents, $config);
    }

    public function read(string $path): string
    {
        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    /**
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        return new DirectoryListing($this->inner->listContents($path, $deep));
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
