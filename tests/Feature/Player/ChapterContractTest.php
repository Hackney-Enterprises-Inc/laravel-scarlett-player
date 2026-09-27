<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;

/*
 * The builder's chapters, run through the chapters plugin's own normaliseChapters()
 * (lifted from the published 1.17.0 build, tests/Fixtures/embed/chapters/). What the
 * player resolves is what the viewer sees on the progress bar, so this is where a wrong
 * key name shows up as a chapter running through a gap.
 */

beforeEach(function (): void {
    if (! binaryOnPath('node')) {
        $this->markTestSkipped('node is not on PATH; the chapters normaliser needs it.');
    }

    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1'));
});

/**
 * @param  list<array<string, mixed>>  $chapters
 * @return list<array<string, mixed>>
 */
function resolveThroughPlayer(array $chapters): array
{
    $emitted = ScarlettPlayer::for('video-1')->withChapters($chapters)->toArray()['chapters']['chapters'];

    $output = (string) shell_exec(sprintf(
        'node %s %s 2>&1',
        escapeshellarg(__DIR__.'/../../Fixtures/embed/chapters/1.17.0/normalise.mjs'),
        escapeshellarg(json_encode($emitted, JSON_THROW_ON_ERROR)),
    ));

    /** @var list<array<string, mixed>> */
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

it('resolves chapter ends the way the player does', function (array $input, array $ends): void {
    expect(array_column(resolveThroughPlayer($input), 'endTime'))->toBe($ends);
})->with([
    'sparse chapters keep their explicit ends through the gaps' => [
        [['time' => 0, 'label' => 'Fight 3', 'endTime' => 2400], ['time' => 2850, 'label' => 'Fight 4', 'endTime' => 4000]],
        [2400, 4000],
    ],
    'the end alias reaches the player as endTime' => [
        [['time' => 0, 'label' => 'A', 'end' => 30], ['time' => 60, 'label' => 'B']],
        [30, null],
    ],
    'no explicit end runs to the next chapter, the last to the end' => [
        [['time' => 0, 'label' => 'A'], ['time' => 60, 'label' => 'B']],
        [60, null],
    ],
    'an end past the next chapter is clamped to its start' => [
        [['time' => 0, 'label' => 'A', 'endTime' => 90], ['time' => 60, 'label' => 'B']],
        [60, null],
    ],
    'unordered input is sorted by time' => [
        [['time' => 60, 'label' => 'B', 'endTime' => 70], ['time' => 0, 'label' => 'A', 'endTime' => 10]],
        [10, 70],
    ],
]);

it('keeps the label the player shows', function (): void {
    expect(array_column(resolveThroughPlayer([['time' => 0, 'label' => 'Intro']]), 'label'))->toBe(['Intro']);
});
