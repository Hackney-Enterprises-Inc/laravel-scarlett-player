<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Feature\Clips\Support;

use Hei\ScarlettPlayer\Contracts\ClipGenerator;
use Hei\ScarlettPlayer\Data\ClipOptions;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\ClipGenerationException;

/**
 * A generator spy: writes a small temp file per call and records what it was asked.
 */
class FakeGenerator implements ClipGenerator
{
    /** @var list<array{source: MediaSource, start: float, end: float, options: ClipOptions, file: string}> */
    public array $calls = [];

    public bool $throws = false;

    public function generate(MediaSource $source, float $start, float $end, ClipOptions $options): string
    {
        if ($this->throws) {
            throw ClipGenerationException::renderFailed($source->id, 'ffmpeg exploded');
        }

        $file = tempnam(sys_get_temp_dir(), 'scarlett-fake-clip-').'.mp4';
        file_put_contents($file, 'rendered-bytes');

        $this->calls[] = compact('source', 'start', 'end', 'options', 'file');

        return $file;
    }
}
