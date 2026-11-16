<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerEmbedUrls;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\UrlSearchParams;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
 * Page to endpoint, end to end (HEI-LSP-24 round 1, F4). Every other attribution test
 * signs its own beacon URL or checks the page's URL with the framework predicate, so each
 * half could drift and the suite stayed green. Here the page is rendered by the real
 * controller for a real partner, the beacon URL is read from its
 * `data-analytics-beacon-url` attribute, and flat bodies are posted to that exact URL
 * through the real route, the sync queue and the Eloquent store, in both forms the player
 * uses: fetch (X-API-Key header, URL unchanged) and unload (no header, the whole query
 * re-serialised with URLSearchParams semantics and api_key appended).
 *
 * The same flow runs on a plain install, behind forced https, under a sub-directory base
 * URL and both together.
 */

beforeEach(function (): void {
    $this->withScarlettConfig([
        'media.resolver' => ArrayResolver::class,
        'embed.always_sign' => true,
        'embed.allowed_domains' => [],
    ]);
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');
    Date::setTestNow('2026-11-10 12:00:00');

    $resolver = app(ResolvesMedia::class);
    assert($resolver instanceof ArrayResolver);
    $resolver->add(ArrayResolver::source('video-1'))->add(ArrayResolver::source('video-2'));

    Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://partner.example']]);
});

afterEach(function (): void {
    Date::setTestNow();
});

/** The install shapes the flow runs under. */
dataset('e2e installs', [
    'plain' => [false, ''],
    'forced https' => [true, ''],
    'sub-directory' => [false, '/sub'],
    'forced https in a sub-directory' => [true, '/sub'],
]);

/**
 * The server variables of a request under a sub-directory install: the front controller
 * lives below the prefix, so the framework strips it from the path it signs and matches.
 *
 * @return array<string, string>
 */
function e2eServer(string $prefix): array
{
    return $prefix === '' ? [] : ['SCRIPT_NAME' => $prefix.'/index.php', 'SCRIPT_FILENAME' => $prefix.'/index.php'];
}

/** The URL as the player's sendBeacon form has it: the query reserialised, api_key appended. */
function e2eUrlSearchParams(string $url, string $key): string
{
    return UrlSearchParams::append($url, 'api_key', $key);
}

/** @param array<string, mixed> $body */
function e2ePost(TestCase $test, string $url, array $body, string $prefix, bool $header): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*'] + e2eServer($prefix);

    if ($header) {
        $server['HTTP_X_API_KEY'] = TestCase::BEACON_KEY;
    }

    return $test->call('POST', $url, [], [], [], $server, (string) json_encode($body));
}

function e2eRow(string $viewId): ?object
{
    return DB::table('scarlett_views')->where('view_id', $viewId)->first();
}

/**
 * Mint the partner page URL, GET it, and return the beacon URL it printed.
 *
 * @return array{url: string, root: string}
 */
function e2ePage(TestCase $test, bool $https, string $prefix, string $media = 'video-1'): array
{
    if ($https) {
        URL::forceScheme('https');
    }

    $root = ($https ? 'https' : 'http').'://localhost'.$prefix;

    $page = ScarlettPlayer::partnerEmbedUrl('acme', $media, root: $root);
    $response = $test->call('GET', $page, [], [], [], e2eServer($prefix));

    $response->assertOk();
    preg_match('/data-analytics-beacon-url="([^"]*)"/', (string) $response->getContent(), $match);

    return ['url' => html_entity_decode($match[1] ?? '', ENT_QUOTES), 'root' => $root];
}

/**
 * Post a viewStart through each transport to the page's beacon URL and read back what
 * was stored.
 *
 * @return array{fetch: ?string, unload: ?string, other: ?string, rows: int}
 */
function e2eAttribution(TestCase $test, bool $https, string $prefix, string $suffix = ''): array
{
    ['url' => $url, 'root' => $root] = e2ePage($test, $https, $prefix);

    expect($url)->toStartWith($root.'/api/scarlett/beacons?expires=');

    $fetch = 'e2e-fetch'.$suffix;
    $unload = 'e2e-unload'.$suffix;
    $other = 'e2e-other'.$suffix;

    e2ePost($test, $url, Beacons::body('viewStart', 0, [], $fetch), $prefix, header: true)->assertNoContent();
    e2ePost($test, e2eUrlSearchParams($url, TestCase::BEACON_KEY), Beacons::body('viewStart', 0, [], $unload), $prefix, header: false)->assertNoContent();
    // The control: the same proof, a body for another media.
    e2ePost($test, $url, Beacons::body('viewStart', 0, ['videoId' => 'video-2'], $other), $prefix, header: true)->assertNoContent();

    return [
        'fetch' => e2eRow($fetch)?->partner_ref,
        'unload' => e2eRow($unload)?->partner_ref,
        'other' => e2eRow($other)?->partner_ref,
        'rows' => DB::table('scarlett_views')->count(),
    ];
}

it('attributes a view posted to the beacon URL the page printed, in both transports', function (bool $https, string $prefix): void {
    $seen = e2eAttribution($this, $https, $prefix);

    expect($seen['fetch'])->toBe('acme')
        ->and($seen['unload'])->toBe('acme')
        ->and($seen['other'])->toBeNull()
        ->and($seen['rows'])->toBe(3);

    $fetch = e2eRow('e2e-fetch');
    $unload = e2eRow('e2e-unload');

    // Media is what the body said, and the verified ref never sits in the server map.
    expect($fetch->video_id)->toBe('video-1')
        ->and($unload->video_id)->toBe('video-1')
        ->and(e2eRow('e2e-other')->video_id)->toBe('video-2')
        ->and((string) $fetch->server)->not->toContain('scarlett_partner')
        ->and((string) $unload->server)->not->toContain('scarlett_partner');
})->with('e2e installs');

it('keeps the printed URL byte-stable through URLSearchParams serialisation', function (): void {
    ['url' => $url] = e2ePage($this, false, '');

    $unload = e2eUrlSearchParams($url, TestCase::BEACON_KEY);

    expect($unload)->toBe($url.'&api_key='.TestCase::BEACON_KEY);

    // The helper really reserialises: a space and a plus in the query come out as the browser's form.
    expect(e2eUrlSearchParams('/x?a=b%20c&d=e+f', 'k+k'))->toBe('/x?a=b+c&d=e+f&api_key=k%2Bk');
});

it('stores null for an expired proof and still ingests', function (bool $https, string $prefix): void {
    ['url' => $url] = e2ePage($this, $https, $prefix);

    // Control: the same URL attributes while it is fresh.
    e2ePost($this, $url, Beacons::body('viewStart', 0, [], 'e2e-fresh'), $prefix, header: true)->assertNoContent();
    expect(e2eRow('e2e-fresh')->partner_ref)->toBe('acme');

    // Past the 24 hour proof life: the page URL is not consulted, only the proof.
    Date::setTestNow(Date::now()->addSeconds(PartnerParams::BEACON_TTL + 1));

    e2ePost($this, $url, Beacons::body('viewStart', 0, [], 'e2e-late-fetch'), $prefix, header: true)->assertNoContent();
    e2ePost($this, e2eUrlSearchParams($url, TestCase::BEACON_KEY), Beacons::body('viewStart', 0, [], 'e2e-late-unload'), $prefix, header: false)->assertNoContent();

    expect(e2eRow('e2e-late-fetch'))->not->toBeNull()
        ->and(e2eRow('e2e-late-fetch')->partner_ref)->toBeNull()
        ->and(e2eRow('e2e-late-unload'))->not->toBeNull()
        ->and(e2eRow('e2e-late-unload')->partner_ref)->toBeNull();
})->with('e2e installs');

/**
 * Mutation control: a minter whose proof is wrong in the signature alone. The same flow
 * must then store no partner_ref while the beacons still ingest, which is exactly what
 * the assertions of the main test would report as a failure.
 */
class E2eCorruptProofUrls extends PartnerEmbedUrls
{
    public function beaconUrl(string $ref, string $mediaId): string
    {
        $url = parent::beaconUrl($ref, $mediaId);

        return (string) preg_replace_callback('/signature=([0-9a-f])/', fn (array $m): string => 'signature='.($m[1] === '0' ? '1' : '0'), $url, 1);
    }
}

it('fails the attribution when only the page minter proof is corrupted, while ingest still works', function (bool $https, string $prefix): void {
    app()->bind(PartnerEmbedUrls::class, fn ($app): PartnerEmbedUrls => new E2eCorruptProofUrls(
        $app->make(UrlGenerator::class),
        $app->make(Repository::class),
        $app->make(EmbedUrlGenerator::class),
    ));

    $seen = e2eAttribution($this, $https, $prefix);

    expect($seen['rows'])->toBe(3)
        ->and($seen['fetch'])->toBeNull()
        ->and($seen['unload'])->toBeNull();
})->with('e2e installs');
