<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Feature\Clips\Support;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * The faked clip disk with seams: a hook that runs just before a visibility write (to
 * interleave another request between a row update and its object write), a write that
 * returns false or throws, and a write that stores fewer bytes than it was given.
 */
class ScriptedDisk extends FilesystemAdapter
{
    /** @var (Closure(string, string): void)|null Runs once, before the next visibility write. */
    public ?Closure $beforeVisibility = null;

    /** @var 'ok'|'false'|'throw'|'short' */
    public string $write = 'ok';

    /** @var list<array{string, string}> */
    public array $visibilityWrites = [];

    public static function install(string $name = 'clips'): self
    {
        $fake = Storage::disk($name);
        $disk = new self($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
        Storage::set($name, $disk);

        return $disk;
    }

    public function setVisibility($path, $visibility)
    {
        if ($this->beforeVisibility !== null) {
            $hook = $this->beforeVisibility;
            $this->beforeVisibility = null;
            $hook($path, $visibility);
        }

        $this->visibilityWrites[] = [$path, $visibility];

        return parent::setVisibility($path, $visibility);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return match ($this->write) {
            'false' => false,
            'throw' => throw new \RuntimeException('Disk full.'),
            'short' => $this->put(trim($path.'/'.$name, '/'), 'x', $options) ? trim($path.'/'.$name, '/') : false,
            default => parent::putFileAs($path, $file, $name, $options),
        };
    }
}
