<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Commands\Partners\CreateCommand;
use Hei\ScarlettPlayer\Commands\Partners\ListCommand;
use Hei\ScarlettPlayer\Commands\Partners\RevokeCommand;
use Hei\ScarlettPlayer\Commands\Partners\RotateCommand;
use Hei\ScarlettPlayer\Commands\Partners\SnippetCommand;
use Hei\ScarlettPlayer\Commands\Partners\UpdateCommand;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
 * The partner artisan commands (HEI-LSP-24 WP5). The provider registers them in WP6, so
 * the suite registers them here. The snippet output is the only place a signed URL
 * appears; the tests prove it validates through the real embed route.
 */

beforeEach(function (): void {
    $this->withScarlettConfig([
        'media.resolver' => ArrayResolver::class,
        'embed.always_sign' => true,
        'embed.allowed_domains' => ['host.example'],
    ]);
    $this->usesMigrations();
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');
    config()->set('app.url', 'https://app.example.test');

    foreach ([CreateCommand::class, UpdateCommand::class, SnippetCommand::class, ListCommand::class, RevokeCommand::class, RotateCommand::class] as $command) {
        Artisan::registerCommand(app($command));
    }

    ScarlettPlayer::fake()->withMedia(
        ArrayResolver::source('video-1'),
        ArrayResolver::source('bad id/with slash'),
        ArrayResolver::source('paid-1', isProtected: true),
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function partnerArtisan(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return [$code, Artisan::output()];
}

/** The page URL out of snippet output: the bare URL, or the iframe src. */
function snippetUrlOf(string $output): string
{
    if (preg_match('/src="([^"]+)"/', $output, $match) === 1) {
        return html_entity_decode($match[1]);
    }

    return trim($output);
}

/** Request a snippet URL through the real route: path and query only, the host is the test request's. */
function visitSnippet(mixed $test, string $url): TestResponse
{
    $parts = parse_url($url);

    return $test->get(($parts['path'] ?? '/').'?'.($parts['query'] ?? ''));
}

function makePartner(string $ref = 'acme'): void
{
    expect(partnerArtisan('scarlett:partners:create', ['ref' => $ref, '--origin' => ['https://partner.example']])[0])->toBe(0);
}

function mintUrl(string $ref = 'acme', string $media = 'video-1', array $options = []): string
{
    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => $ref, 'media' => $media, '--url' => true, ...$options]);
    expect($code)->toBe(0);

    return snippetUrlOf($output);
}

it('create refuses bad origins and refs and stores nothing', function (string $ref, array $options): void {
    [$code, $output] = partnerArtisan('scarlett:partners:create', ['ref' => $ref, ...$options]);

    expect($code)->toBe(1)
        ->and(Partner::query()->count())->toBe(0)
        ->and($output)->not->toBe('');
})->with([
    'bare star' => ['acme', ['--origin' => ['*']]],
    'http non-local' => ['acme', ['--origin' => ['http://evil.test']]],
    'path' => ['acme', ['--origin' => ['https://x.test/path']]],
    'one bad among good' => ['acme', ['--origin' => ['https://ok.test', 'https://x.test/path']]],
    'semicolon injection' => ['acme', ['--origin' => ['https://a.test; script-src *']]],
    'missing origin' => ['acme', []],
    'uppercase ref' => ['Acme', ['--origin' => ['https://ok.test']]],
    'over-long ref' => [str_repeat('a', 65), ['--origin' => ['https://ok.test']]],
    'bad expires' => ['acme', ['--origin' => ['https://ok.test'], '--expires' => 'not a date']],
    'past expires' => ['acme', ['--origin' => ['https://ok.test'], '--expires' => '2001-01-01']],
]);

it('create accepts exact https origins and localhost http, and names the partner', function (): void {
    [$code] = partnerArtisan('scarlett:partners:create', [
        'ref' => 'acme',
        '--name' => 'Acme Inc',
        '--origin' => ['https://Partner.example', 'https://*.partner.example:8443', 'http://localhost:3000'],
    ]);

    $partner = Partner::query()->where('ref', 'acme')->firstOrFail();

    expect($code)->toBe(0)
        ->and($partner->name)->toBe('Acme Inc')
        ->and($partner->origins)->toBe(['https://partner.example', 'https://*.partner.example:8443', 'http://localhost:3000'])
        ->and($partner->expires_at)->toBeNull()
        ->and($partner->revoked_at)->toBeNull();
});

it('create refuses a ref that already exists and never prints the key id', function (): void {
    [, $first] = partnerArtisan('scarlett:partners:create', ['ref' => 'acme', '--origin' => ['https://partner.example']]);
    $keyId = Partner::query()->firstOrFail()->key_id;

    [$code, $second] = partnerArtisan('scarlett:partners:create', ['ref' => 'acme', '--origin' => ['https://other.example']]);

    expect($code)->toBe(1)
        ->and(Partner::query()->count())->toBe(1)
        ->and(Partner::query()->firstOrFail()->origins)->toBe(['https://partner.example'])
        ->and($first.$second)->not->toContain($keyId);
});

it('create stores a contract end date', function (): void {
    Carbon::setTestNow('2026-11-10 12:00:00');

    [$code] = partnerArtisan('scarlett:partners:create', ['ref' => 'acme', '--origin' => ['https://partner.example'], '--expires' => '2027-01-01']);

    expect($code)->toBe(0)
        ->and(Partner::query()->firstOrFail()->expires_at?->toDateString())->toBe('2027-01-01');
});

it('update replaces the origins and validates each one', function (): void {
    makePartner();

    [$code] = partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--origin' => ['https://one.example', 'https://two.example']]);
    expect($code)->toBe(0)
        ->and(Partner::query()->firstOrFail()->origins)->toBe(['https://one.example', 'https://two.example']);

    foreach (['*', 'http://evil.test', 'https://x.test/path'] as $bad) {
        [$code] = partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--origin' => ['https://three.example', $bad]]);
        expect($code)->toBe(1)
            ->and(Partner::query()->firstOrFail()->origins)->toBe(['https://one.example', 'https://two.example']);
    }
});

it('update changes the name and the expiry, clears it, and refuses an unknown ref or nothing to change', function (): void {
    makePartner();

    expect(partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--name' => 'New name', '--expires' => '2099-01-01'])[0])->toBe(0);
    $partner = Partner::query()->firstOrFail();
    expect($partner->name)->toBe('New name')
        ->and($partner->expires_at?->toDateString())->toBe('2099-01-01')
        ->and($partner->origins)->toBe(['https://partner.example']);

    expect(partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--clear-expires' => true])[0])->toBe(0)
        ->and(Partner::query()->firstOrFail()->expires_at)->toBeNull()
        ->and(partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--clear-expires' => true, '--expires' => '2099-01-01'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:update', ['ref' => 'acme'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:update', ['ref' => 'nobody', '--name' => 'x'])[0])->toBe(1);
});

it('update never changes the key id', function (): void {
    makePartner();
    $keyId = Partner::query()->firstOrFail()->key_id;

    partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--origin' => ['https://one.example'], '--name' => 'x']);

    expect(Partner::query()->firstOrFail()->key_id)->toBe($keyId);
});

it('list shows ref, origins and state, and never a key id or a signature', function (): void {
    makePartner('acme');
    makePartner('beta');
    partnerArtisan('scarlett:partners:update', ['ref' => 'beta', '--expires' => '2099-01-01']);
    partnerArtisan('scarlett:partners:revoke', ['ref' => 'beta']);
    $url = mintUrl('acme');

    [$code, $output] = partnerArtisan('scarlett:partners:list');

    expect($code)->toBe(0)
        ->and($output)->toContain('acme', 'beta', 'https://partner.example', 'revoked', 'active', '2099-01-01')
        ->and($output)->not->toContain('signature')
        ->and($output)->not->toContain('scp_key')
        ->and($output)->not->toContain($url);

    foreach (Partner::query()->get() as $partner) {
        expect($output)->not->toContain($partner->key_id);
    }
});

it('list says so when there are no partners, and marks an expired one', function (): void {
    [$code, $output] = partnerArtisan('scarlett:partners:list');
    expect($code)->toBe(0)->and($output)->toContain('No partners');

    Partner::create(['ref' => 'old', 'name' => 'Old', 'origins' => ['https://old.example'], 'expires_at' => Carbon::now()->subDay()]);

    expect(partnerArtisan('scarlett:partners:list')[1])->toContain('old', 'expired');
});

it('snippet prints an iframe whose URL validates through the real route', function (): void {
    makePartner();

    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1', '--width' => '800', '--height' => '450']);
    $url = snippetUrlOf($output);

    expect($code)->toBe(0)
        ->and($output)->toContain('<iframe', 'width="800"', 'height="450"')
        ->and($url)->toStartWith('https://app.example.test/v/video-1?')
        ->and($url)->toContain('scp_key=', 'signature=');

    visitSnippet($this, $url)->assertOk();
});

it('snippet --url prints the bare URL', function (): void {
    makePartner();

    $url = mintUrl();

    expect($url)->toStartWith('https://app.example.test/v/video-1?')
        ->and($url)->not->toContain('<iframe');
});

it('snippet validates when app.url is another scheme and host than the request, and with --root', function (): void {
    makePartner();

    config()->set('app.url', 'http://wrong.invalid:9999');
    visitSnippet($this, mintUrl())->assertOk();

    config()->set('app.url', 'https://app.example.test');
    $rooted = mintUrl(options: ['--root' => 'https://public.example.com/base/']);

    expect($rooted)->toStartWith('https://public.example.com/base/v/video-1?');
    // The signature is over the relative /v/... path, so a request that reaches the app without the proxy prefix passes.
    visitSnippet($this, substr($rooted, strlen('https://public.example.com/base')))->assertOk();
});

it('snippet refuses a bad --root', function (string $root): void {
    makePartner();

    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1', '--root' => $root]);

    expect($code)->toBe(1)->and($output)->not->toContain('signature=');
})->with(['javascript:alert(1)', 'https://x.test/?a=b', 'ftp://x.test', 'https://']);

it('snippet refuses when embed.always_sign is off', function (): void {
    makePartner();
    config()->set('scarlett-player.embed.always_sign', false);

    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1']);

    expect($code)->toBe(1)
        ->and($output)->toContain('always_sign')
        ->and($output)->not->toContain('signature=');
});

it('snippet refuses a media id outside the signed charset', function (): void {
    makePartner();

    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'bad id/with slash']);

    expect($code)->toBe(1)->and($output)->not->toContain('signature=');
});

it('snippet refuses protected media without --expires, and --expires beyond 90 days', function (): void {
    makePartner();

    expect(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'paid-1'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'paid-1', '--expires' => '91'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1', '--expires' => '91'])[0])->toBe(1);

    $url = mintUrl(media: 'paid-1', options: ['--expires' => '30']);

    expect($url)->toContain('expires=');
    visitSnippet($this, $url)->assertOk();
});

it('snippet refuses a past expiry, a nonsense expiry, an unknown partner, unknown media and an expired partner', function (): void {
    makePartner();
    Partner::create(['ref' => 'old', 'name' => 'Old', 'origins' => ['https://old.example'], 'expires_at' => Carbon::now()->subDay()]);

    expect(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1', '--expires' => '2001-01-01'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1', '--expires' => 'soon-ish'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:snippet', ['ref' => 'nobody', 'media' => 'video-1'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'missing-media'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:snippet', ['ref' => 'old', 'media' => 'video-1'])[0])->toBe(1);
});

it('snippet warns, without refusing, when embed.allowed_domains is empty', function (): void {
    makePartner();

    config()->set('scarlett-player.embed.allowed_domains', []);
    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1']);

    expect($code)->toBe(0)
        ->and($output)->toContain('WARNING', 'allowed_domains', 'framed by *', 'signature=');

    config()->set('scarlett-player.embed.allowed_domains', ['host.example']);
    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1']);

    expect($code)->toBe(0)->and($output)->not->toContain('WARNING');
});

it('snippet warns when embed.allowed_domains holds only blank entries, as the page frames them with *', function (): void {
    makePartner();

    config()->set('scarlett-player.embed.allowed_domains', ['', ' ']);
    [$code, $output] = partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1']);

    expect($code)->toBe(0)->and($output)->toContain('WARNING', 'framed by *');
});

it('revoke kills the snippet, restore leaves it dead and a fresh snippet works', function (): void {
    makePartner();
    $old = mintUrl();
    visitSnippet($this, $old)->assertOk();

    expect(partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme'])[0])->toBe(0)
        ->and(Partner::query()->firstOrFail()->revoked_at)->not->toBeNull();
    visitSnippet($this, $old)->assertForbidden();
    expect(partnerArtisan('scarlett:partners:snippet', ['ref' => 'acme', 'media' => 'video-1'])[0])->toBe(1);

    $keyBefore = Partner::query()->firstOrFail()->key_id;
    expect(partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme', '--restore' => true])[0])->toBe(0);

    $partner = Partner::query()->firstOrFail();
    expect($partner->revoked_at)->toBeNull()
        ->and($partner->key_id)->not->toBe($keyBefore);

    visitSnippet($this, $old)->assertForbidden();
    visitSnippet($this, mintUrl())->assertOk();
});

it('revoke and restore refuse an unknown ref, and restore refuses a partner that is not revoked', function (): void {
    makePartner();
    $keyId = Partner::query()->firstOrFail()->key_id;

    expect(partnerArtisan('scarlett:partners:revoke', ['ref' => 'nobody'])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:revoke', ['ref' => 'nobody', '--restore' => true])[0])->toBe(1)
        ->and(partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme', '--restore' => true])[0])->toBe(1)
        ->and(Partner::query()->firstOrFail()->key_id)->toBe($keyId);
});

it('revoke keeps the original revocation time when run twice', function (): void {
    makePartner();
    Carbon::setTestNow('2026-11-10 12:00:00');
    partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme']);
    Carbon::setTestNow('2026-11-11 12:00:00');
    partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme']);

    expect(Partner::query()->firstOrFail()->revoked_at?->toDateString())->toBe('2026-11-10');
});

it('rotate kills the old snippet, and a new one works', function (): void {
    makePartner();
    $old = mintUrl();
    $keyBefore = Partner::query()->firstOrFail()->key_id;

    [$code, $output] = partnerArtisan('scarlett:partners:rotate', ['ref' => 'acme']);

    expect($code)->toBe(0)
        ->and(Partner::query()->firstOrFail()->key_id)->not->toBe($keyBefore)
        ->and($output)->not->toContain($keyBefore)
        ->and($output)->not->toContain(Partner::query()->firstOrFail()->key_id);

    visitSnippet($this, $old)->assertForbidden();
    visitSnippet($this, mintUrl())->assertOk();
    expect(partnerArtisan('scarlett:partners:rotate', ['ref' => 'nobody'])[0])->toBe(1);
});

it('prints and logs no signature or key id outside the snippet output', function (): void {
    Log::spy();

    [, $create] = partnerArtisan('scarlett:partners:create', ['ref' => 'acme', '--origin' => ['https://partner.example']]);
    $outputs = [$create];
    mintUrl();
    $outputs[] = partnerArtisan('scarlett:partners:update', ['ref' => 'acme', '--name' => 'x'])[1];
    $outputs[] = partnerArtisan('scarlett:partners:list')[1];
    $outputs[] = partnerArtisan('scarlett:partners:rotate', ['ref' => 'acme'])[1];
    $outputs[] = partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme'])[1];
    $outputs[] = partnerArtisan('scarlett:partners:revoke', ['ref' => 'acme', '--restore' => true])[1];

    $keyId = Partner::query()->firstOrFail()->key_id;

    foreach ($outputs as $output) {
        expect($output)->not->toContain('signature')->and($output)->not->toContain('scp_key')->and($output)->not->toContain($keyId);
    }

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});
