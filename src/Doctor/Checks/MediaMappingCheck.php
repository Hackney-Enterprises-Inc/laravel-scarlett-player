<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Exceptions\IncompleteMediaMappingException;
use Hei\ScarlettPlayer\Media\ConfigModelResolver;

/**
 * The media mapping is complete: the model implements ScarlettMedia, or the attribute
 * map names playback_url, is_live and is_protected. Caught here, at install time,
 * rather than on the first request.
 */
class MediaMappingCheck implements Check
{
    public function __construct(
        private readonly ResolvesMedia $resolver,
    ) {}

    public function name(): string
    {
        return 'media mapping';
    }

    public function run(): CheckResult
    {
        if (! $this->resolver instanceof ConfigModelResolver) {
            return CheckResult::pass('custom resolver ['.$this->resolver::class.'] is bound; its mapping is not checked here');
        }

        try {
            $this->resolver->assertMappingComplete();
        } catch (IncompleteMediaMappingException $e) {
            return CheckResult::fail($e->getMessage());
        }

        return CheckResult::pass('media.model and its mapping are complete');
    }
}
