<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ProcessesBeacon;
use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconContextException;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Hei\ScarlettPlayer\Testing\FakeBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\UrlSearchParams;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
 * Partner attribution on the beacon route (HEI-LSP-24 WP3). The page mints a relative-signed
 * beacon URL carrying scp_ref, scp_media and a 24 h expires; the browser appends api_key
 * and posts FLAT top-level keys. The route verifies the proof without touching the
 * database, carries the verified ref in its own payload property, and the store writes
 * scarlett_views.partner_ref set-once, only for the media the proof names.
 *
 * Every case uses its own view id so no case can borrow another's row.
 */

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    Date::setTestNow('2026-11-10 12:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

/**
 * The relative-signed beacon URL the page would carry, built from the shared contract.
 *
 * @param  int|null  $expires  absolute epoch seconds; null is now plus the proof lifetime
 */
function partnerBeaconUrl(string $ref = 'acme', string $media = 'video-1', ?int $expires = null): string
{
    return URL::signedRoute(
        'scarlett.beacons.store',
        [PartnerParams::REF => $ref, PartnerParams::MEDIA => $media],
        Date::createFromTimestamp($expires ?? Date::now()->getTimestamp() + PartnerParams::BEACON_TTL),
        absolute: false,
    );
}

/** The URL as the player's sendBeacon form has it: the whole query reserialised through URLSearchParams semantics, api_key appended. */
function withApiKey(string $url): string
{
    return UrlSearchParams::append($url, 'api_key', TestCase::BEACON_KEY);
}

/**
 * POST a flat beacon body to a beacon URL.
 *
 * @param  array<string, mixed>  $body
 */
function postPartnerBeacon(TestCase $test, string $url, array $body): TestResponse
{
    return $test->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => '*/*',
    ], (string) json_encode($body));
}

/** @return array<string, mixed> */
function partnerBody(string $viewId, string $event = 'viewStart', array $extra = [], int $offset = 0): array
{
    return Beacons::body($event, $offset, $extra, $viewId);
}

function partnerViewRow(string $viewId): ?object
{
    return DB::table('scarlett_views')->where('view_id', $viewId)->first();
}

describe('a verified beacon', function (): void {
    it('stores partner_ref from a correctly signed beacon url with api_key appended', function (): void {
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-first'))->assertNoContent();

        expect(partnerViewRow('v-first')->partner_ref)->toBe('acme');
    });

    it('still attributes through a pipeline step that calls withServer()', function (): void {
        config()->set('scarlett-player.beacons.pipeline', [PartnerServerStep::class]);

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-pipe'))->assertNoContent();

        $row = partnerViewRow('v-pipe');
        expect($row->partner_ref)->toBe('acme')
            ->and(json_decode((string) $row->server, true))->toBe(['tenant' => 7]);
    });

    it('does not let a pipeline step forge the ref', function (): void {
        config()->set('scarlett-player.beacons.pipeline', [PartnerForgeStep::class]);
        $this->withoutExceptionHandling();

        expect(fn () => postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-1')), partnerBody('v-forge')))
            ->toThrow(InvalidBeaconContextException::class);

        expect(partnerViewRow('v-forge'))->toBeNull();
    });

    it('never lets a pipeline server value reach partner_ref on an unverified beacon', function (): void {
        config()->set('scarlett-player.beacons.pipeline', [PartnerForgeStep::class]);
        $this->withoutExceptionHandling();

        expect(fn () => postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), partnerBody('v-forge2')))
            ->toThrow(InvalidBeaconContextException::class);

        expect(partnerViewRow('v-forge2'))->toBeNull();
    });

    it('works with beacons.context unset', function (): void {
        expect(config('scarlett-player.beacons.context'))->toBeNull();

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-noctx'))->assertNoContent();

        expect(partnerViewRow('v-noctx')->partner_ref)->toBe('acme');
    });

    it('merges with a host context and keeps its keys', function (): void {
        config()->set('scarlett-player.beacons.context', PartnerHostContext::class);

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-ctx'))->assertNoContent();

        $row = partnerViewRow('v-ctx');
        expect($row->partner_ref)->toBe('acme')
            ->and(json_decode((string) $row->server, true))->toBe(['tenant' => 7]);
    });

    it('refuses a host context that returns scarlett_partner and queues nothing', function (): void {
        Queue::fake();
        config()->set('scarlett-player.beacons.context', PartnerForgeContext::class);
        $this->withoutExceptionHandling();

        expect(fn () => postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-hostkey')))
            ->toThrow(InvalidBeaconContextException::class, 'scarlett_partner');

        Queue::assertNothingPushed();
    });

    it('queues the verified ref in its own property, outside the server map', function (): void {
        Queue::fake();

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-queue'))->assertNoContent();

        $payload = Queue::pushed(ProcessBeacon::class)->first()->payload;
        $restored = unserialize(serialize($payload));

        expect($payload->server)->toBe([])
            ->and($payload->partnerRef())->toBe('acme')
            ->and($restored->partnerRef())->toBe('acme')
            ->and($restored->withServer(['tenant' => 1])->partnerRef())->toBe('acme')
            ->and($restored->withCustom([])->partnerRef())->toBe('acme')
            ->and($restored->withIp(null)->partnerRef())->toBe('acme');
    });

    it('records it in the fake ledger', function (): void {
        $fake = new FakeBeaconStore;
        app()->instance(BeaconStore::class, $fake);

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-fake'))->assertNoContent();

        $fake->assertRecorded(fn (BeaconPayload $p): bool => $p->partnerRef() === 'acme' && ($p->toArray()['scarlett_partner'] ?? null) === 'acme');
    });

    it('keeps the host custom dimension `partner` with and without a context', function (bool $context): void {
        config()->set('scarlett-player.beacons.context', $context ? PartnerHostContext::class : null);

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-dim-'.(int) $context, extra: ['partner' => 'host-dim']))->assertNoContent();
        postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), partnerBody('v-dimu-'.(int) $context, extra: ['partner' => 'host-dim']))->assertNoContent();

        foreach (['v-dim-', 'v-dimu-'] as $prefix) {
            expect(json_decode((string) partnerViewRow($prefix.(int) $context)->custom, true))->toBe(['partner' => 'host-dim']);
        }
    })->with(['with a context' => true, 'without' => false]);

    it('reads the proof without any partner table query', function (): void {
        Queue::fake();
        DB::connection()->enableQueryLog();

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-nodb'))->assertNoContent();

        expect(collect(DB::getQueryLog())->pluck('query')->implode(' '))->not->toContain('scarlett_partners');
    });

    it('attributes a revoked or deleted partner within the proof life', function (): void {
        $partner = Partner::create(['ref' => 'gone', 'name' => 'Gone', 'origins' => ['https://gone.test']]);
        $partner->forceFill(['revoked_at' => Date::now()])->save();

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('gone')), partnerBody('v-revoked'))->assertNoContent();
        $partner->delete();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('gone')), partnerBody('v-deleted'))->assertNoContent();

        expect(partnerViewRow('v-revoked')->partner_ref)->toBe('gone')
            ->and(partnerViewRow('v-deleted')->partner_ref)->toBe('gone');
    });

    it('leaves the hash and queued payload of a partner-less beacon unchanged', function (): void {
        Queue::fake();
        $body = partnerBody('v-plain');

        postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), $body)->assertNoContent();

        $payload = Queue::pushed(ProcessBeacon::class)->first()->payload;
        expect($payload->bodyHash)->toBe(BeaconPayload::fromArray($body)->bodyHash)
            ->and($payload->partnerRef())->toBeNull()
            ->and($payload->server)->toBe([])
            ->and(array_keys(get_object_vars($payload)))->not->toContain('partnerRef')
            ->and(serialize($payload))->not->toContain('partnerRef');
    });
});

describe('an unverified beacon', function (): void {
    it('stores null for unsigned, tampered, other-media, wrong-key-shaped and past-expiry proofs and still ingests', function (): void {
        $signed = partnerBeaconUrl('acme', 'video-1');
        $cases = [
            'v-unsigned' => '/api/scarlett/beacons?scp_ref=acme&scp_media=video-1&expires='.(Date::now()->getTimestamp() + 600),
            'v-tampered' => str_replace('scp_ref=acme', 'scp_ref=other', $signed),
            'v-badsig' => preg_replace('/signature=[a-f0-9]{6}/', 'signature=000000', $signed),
            'v-othermedia' => partnerBeaconUrl('acme', 'video-2'),
            'v-expired' => partnerBeaconUrl('acme', 'video-1', Date::now()->getTimestamp() - 1),
            'v-toolong' => partnerBeaconUrl('acme', 'video-1', Date::now()->getTimestamp() + PartnerParams::BEACON_TTL + 3600),
            'v-badref' => partnerBeaconUrl('ACME UPPER', 'video-1'),
        ];

        foreach ($cases as $viewId => $url) {
            postPartnerBeacon($this, withApiKey($url), partnerBody($viewId))->assertNoContent();

            $row = partnerViewRow($viewId);
            expect($row)->not->toBeNull($viewId)
                ->and($row->partner_ref)->toBeNull($viewId);
        }
    });

    it('keeps a flat scarlett_partner body key as a custom dimension and never reads it', function (): void {
        postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), partnerBody('v-spoof', extra: ['scarlett_partner' => 'x']))->assertNoContent();

        $row = partnerViewRow('v-spoof');
        expect($row->partner_ref)->toBeNull()
            ->and(json_decode((string) $row->custom, true))->toBe(['scarlett_partner' => 'x']);
    });

    // The duplicates break the signature first (it covers every raw pair); the guard
    // branch itself is tested in tests/Unit/Partners/PartnerQueryGuardsTest.php.
    it('stores null for a duplicate or array scp parameter, which fails the signature', function (): void {
        $signed = partnerBeaconUrl('acme', 'video-1');
        [$path, $query] = explode('?', $signed, 2);

        $cases = [
            'v-dupref' => $path.'?scp_ref=evil&'.$query,
            'v-dupmedia' => $path.'?scp_media=video-1&'.$query,
            'v-dupexp' => $path.'?expires=1&'.$query,
            'v-arrref' => $path.'?scp_ref[]=evil&'.$query,
        ];

        foreach ($cases as $viewId => $url) {
            postPartnerBeacon($this, withApiKey($url), partnerBody($viewId))->assertNoContent();

            expect(partnerViewRow($viewId)->partner_ref)->toBeNull($viewId);
        }
    });

    it('verifies the same proof when it is not the duplicate form (control)', function (): void {
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-control'))->assertNoContent();

        expect(partnerViewRow('v-control')->partner_ref)->toBe('acme');
    });
});

describe('a verified beacon and the flat scarlett_partner key', function (): void {
    it('strips the spoof and lets the verified ref win', function (): void {
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-strip', extra: ['scarlett_partner' => 'evil', 'plan' => 'ppv']))->assertNoContent();

        $row = partnerViewRow('v-strip');
        expect($row->partner_ref)->toBe('acme')
            ->and(json_decode((string) $row->custom, true))->toBe(['plan' => 'ppv'])
            ->and($row->server)->toBeNull();
    });
});

describe('set-once and media binding', function (): void {
    it('keeps the first partner in either order and on redelivery, with no scarlett_partner in server', function (): void {
        config()->set('scarlett-player.beacons.context', PartnerHostContext::class);

        $deliver = function (string $view, string $ref, int $offset = 0, string $event = 'viewStart'): void {
            postPartnerBeacon($this, withApiKey(partnerBeaconUrl($ref)), partnerBody($view, $event, [], $offset))->assertNoContent();
        };

        $deliver('v-ab', 'alpha', 0);
        $deliver('v-ab', 'beta', 10, 'heartbeat');
        $deliver('v-ba', 'beta', 0);
        $deliver('v-ba', 'alpha', 10, 'heartbeat');
        $deliver('v-dup', 'alpha', 0);
        $deliver('v-dup', 'alpha', 0);

        expect(partnerViewRow('v-ab')->partner_ref)->toBe('alpha')
            ->and(partnerViewRow('v-ba')->partner_ref)->toBe('beta')
            ->and(partnerViewRow('v-dup')->partner_ref)->toBe('alpha');

        foreach (['v-ab', 'v-ba', 'v-dup'] as $view) {
            expect((string) partnerViewRow($view)->server)->not->toContain('scarlett_partner');
        }
    });

    it('does not attribute a row stored for another video: unsigned B first, then a proof for A on the same view', function (): void {
        postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), partnerBody('v-shared', extra: ['videoId' => 'video-B']))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-shared', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();

        $row = partnerViewRow('v-shared');
        expect($row->video_id)->toBe('video-B')
            ->and($row->partner_ref)->toBeNull();
    });

    // MySQL compares strings case-insensitively under its default collation: video-a and
    // video-A are different media here and must stay different at the canonical merge.
    it('compares the stored media exactly, so a proof for video-A never attributes a row stored for video-a', function (): void {
        $plain = '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY);

        postPartnerBeacon($this, $plain, partnerBody('v-case', extra: ['videoId' => 'video-a']))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-case', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();
        // redelivery
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-case', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();

        expect(partnerViewRow('v-case')->video_id)->toBe('video-a')
            ->and(partnerViewRow('v-case')->partner_ref)->toBeNull();

        // Reverse capitalisation.
        postPartnerBeacon($this, $plain, partnerBody('v-case2', extra: ['videoId' => 'video-A']))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-a')), partnerBody('v-case2', 'heartbeat', ['videoId' => 'video-a'], 10))->assertNoContent();

        expect(partnerViewRow('v-case2')->video_id)->toBe('video-A')
            ->and(partnerViewRow('v-case2')->partner_ref)->toBeNull();
    });

    it('attributes a row stored for exactly the proof media (positive control)', function (): void {
        $plain = '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY);

        postPartnerBeacon($this, $plain, partnerBody('v-exact', extra: ['videoId' => 'video-A']))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-exact', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-exact', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();

        expect(partnerViewRow('v-exact')->partner_ref)->toBe('acme');
    });

    it('does not attribute in the reverse order either, nor on redelivery', function (): void {
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-rev', extra: ['videoId' => 'video-A']))->assertNoContent();
        postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), partnerBody('v-rev', 'heartbeat', ['videoId' => 'video-B'], 10))->assertNoContent();
        // redelivery of the proof beacon
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-rev', extra: ['videoId' => 'video-A']))->assertNoContent();

        $row = partnerViewRow('v-rev');
        expect($row->video_id)->toBe('video-A')
            ->and($row->partner_ref)->toBe('acme');

        postPartnerBeacon($this, '/api/scarlett/beacons?api_key='.urlencode(TestCase::BEACON_KEY), partnerBody('v-rev2', extra: ['videoId' => 'video-B']))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-rev2', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme', 'video-A')), partnerBody('v-rev2', 'heartbeat', ['videoId' => 'video-A'], 10))->assertNoContent();

        expect(partnerViewRow('v-rev2')->partner_ref)->toBeNull();
    });
});

it('shows the partner ref in views:inspect', function (): void {
    postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-inspect'))->assertNoContent();

    $this->artisan('scarlett:views:inspect', ['view' => 'v-inspect', '--events' => '0'])
        ->expectsOutputToContain('acme')
        ->assertSuccessful();
});

describe('older schemas', function (): void {
    it('still ingests a table without partner_ref', function (): void {
        Schema::table('scarlett_views', function ($table): void {
            $table->dropIndex('scarlett_views_partner_ref_index');
        });
        Schema::table('scarlett_views', function ($table): void {
            $table->dropColumn('partner_ref');
        });

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-nocol'))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-nocol', 'heartbeat', [], 10))->assertNoContent();

        expect(partnerViewRow('v-nocol'))->not->toBeNull();
    });

    it('still ingests a verified partner on a table without the server columns', function (): void {
        Schema::table('scarlett_views', function ($table): void {
            $table->dropColumn(['server', 'server_stamps']);
        });

        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-noserver'))->assertNoContent();
        postPartnerBeacon($this, withApiKey(partnerBeaconUrl('acme')), partnerBody('v-noserver', 'heartbeat', ['watchTime' => 5], 10))->assertNoContent();

        expect(partnerViewRow('v-noserver')->partner_ref)->toBe('acme');
    });
});

/** A host pipeline step that replaces the server map, as the README documents. */
final class PartnerServerStep implements ProcessesBeacon
{
    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload
    {
        return $next($payload->withServer(['tenant' => 7]));
    }
}

/** A step that tries to set the reserved key. */
final class PartnerForgeStep implements ProcessesBeacon
{
    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload
    {
        return $next($payload->withServer(['scarlett_partner' => 'forged']));
    }
}

final class PartnerHostContext implements ResolvesBeaconContext
{
    public function resolve(Request $request, BeaconPayload $payload): array
    {
        return ['tenant' => 7];
    }
}

final class PartnerForgeContext implements ResolvesBeaconContext
{
    public function resolve(Request $request, BeaconPayload $payload): array
    {
        return ['scarlett_partner' => 'forged'];
    }
}
