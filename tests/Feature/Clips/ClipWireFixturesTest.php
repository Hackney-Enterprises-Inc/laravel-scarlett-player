<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;

/*
 * The clip pair from the player 1.17.0 capture (tests/Fixtures/wire/1.17.0/clip.create.json
 * and clip.retry.json, see that directory's PROVENANCE.md), replayed as recorded through
 * the real create route. The retry carries the same clientRequestId and body
 * but a later capturedAt, so the idempotency is keyed on clientRequestId alone.
 *
 * The fixtures are never edited: when a captured body disagrees with the package, the
 * package changes.
 */

/**
 * @return array<string, mixed>
 */
function clipWire(string $file): array
{
    $path = dirname(__DIR__, 2).'/Fixtures/wire/1.17.0/'.$file;

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['request'];
}

/**
 * Replay a captured clip request: method, path and body as recorded, with the
 * content-type and the CSRF header the plugin sent; the browser-only headers dropped.
 *
 * @param  array<string, mixed>  $request
 * @param  array<string, string>  $server
 */
function replayClipWire(TestCase $test, array $request, array $server = []): TestResponse
{
    $server['CONTENT_TYPE'] = (string) $request['headers']['content-type'];
    $server['HTTP_X_CSRF_TOKEN'] = (string) $request['headers']['x-csrf-token'];

    return $test->call($request['method'], $request['path'], [], [], [], $server, (string) json_encode($request['body']));
}

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    T::media(T::source(clipWire('clip.create.json')['body']['mediaId']));
    Bus::fake([RenderClip::class]);
    $this->actingAs($this->user = T::user());
});

it('accepts the captured ClipRange with 202, the uuid, status URL and a re-derived duration', function (): void {
    $body = clipWire('clip.create.json')['body'];

    $response = replayClipWire($this, clipWire('clip.create.json'))->assertStatus(202);
    $clip = Clip::query()->sole();

    $response->assertJson([
        'uuid' => $clip->uuid,
        'status' => 'pending',
        'mediaId' => $body['mediaId'],
        'title' => $body['title'],
        'startTime' => $body['startTime'],
        'endTime' => $body['endTime'],
        'duration' => $body['endTime'] - $body['startTime'],
        'statusUrl' => route('scarlett.clips.show', ['uuid' => $clip->uuid]),
        'playbackUrl' => null,
    ]);

    expect($body['isLive'])->toBeFalse()
        ->and([$body['seekableStart'], $body['seekableEnd'], $body['startDate'], $body['endDate']])->toBe([null, null, null, null]);

    Bus::assertDispatchedTimes(RenderClip::class, 1);
});

it('answers the captured retry with the first clip, one row and one job, though capturedAt differs', function (): void {
    $create = clipWire('clip.create.json');
    $retry = clipWire('clip.retry.json');

    expect($retry['body']['clientRequestId'])->toBe($create['body']['clientRequestId'])
        ->and($retry['body']['capturedAt'])->not->toBe($create['body']['capturedAt'])
        ->and(array_diff_key($retry['body'], ['capturedAt' => true]))->toBe(array_diff_key($create['body'], ['capturedAt' => true]));

    $first = replayClipWire($this, $create)->assertStatus(202)->json('uuid');
    $second = replayClipWire($this, $retry)->assertStatus(202)->json('uuid');

    expect($second)->toBe($first)
        ->and(Clip::query()->count())->toBe(1);

    Bus::assertDispatchedTimes(RenderClip::class, 1);
});

it('accepts the captured request with CSRF enforced, the X-CSRF-TOKEN it carries matching the session token', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    $request = clipWire('clip.create.json');

    $this->withSession([
        '_token' => (string) $request['headers']['x-csrf-token'],
        'login_web_'.sha1(SessionGuard::class) => $this->user->id,
    ]);

    replayClipWire($this, $request)->assertStatus(202);
});
