<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Partners\PartnerOrigin;

it('accepts strict origins and returns the lowercase canonical form', function (string $origin, string $canonical): void {
    expect(PartnerOrigin::canonical($origin))->toBe($canonical)
        ->and(PartnerOrigin::isValid($origin))->toBeTrue()
        ->and(PartnerOrigin::normalise($origin))->toBe($canonical);
})->with([
    'https host' => ['https://partner.example', 'https://partner.example'],
    'uppercase' => ['HTTPS://Partner.EXAMPLE', 'https://partner.example'],
    'port' => ['https://partner.example:8443', 'https://partner.example:8443'],
    'wildcard' => ['https://*.partner.example', 'https://*.partner.example'],
    'wildcard and port' => ['https://*.Partner.example:444', 'https://*.partner.example:444'],
    'highest port' => ['https://partner.example:65535', 'https://partner.example:65535'],
    'localhost over http' => ['http://localhost', 'http://localhost'],
    'localhost port over http' => ['http://localhost:5173', 'http://localhost:5173'],
    'loopback over http' => ['http://127.0.0.1:8000', 'http://127.0.0.1:8000'],
    'localhost over https' => ['https://localhost', 'https://localhost'],
    'single label host' => ['https://intranet', 'https://intranet'],
]);

it('refuses everything that is not a strict origin', function (mixed $origin): void {
    expect(PartnerOrigin::canonical($origin))->toBeNull()
        ->and(PartnerOrigin::isValid($origin))->toBeFalse()
        ->and(fn () => PartnerOrigin::normalise($origin))->toThrow(InvalidPartnerException::class);
})->with([
    'bare star' => ['*'],
    'star with scheme' => ['https://*'],
    'wildcard of a whole suffix' => ['https://*.com'],
    'double wildcard' => ['https://*.*.example'],
    'wildcard in the middle' => ['https://a.*.example'],
    'scheme only' => ['https://'],
    'scheme only no slashes' => ['https:'],
    'no scheme' => ['partner.example'],
    'http for a real host' => ['http://partner.example'],
    'http wildcard on a dev host' => ['http://*.localhost'],
    'http for a private ip' => ['http://192.168.0.1'],
    'ftp' => ['ftp://partner.example'],
    'javascript' => ['javascript://partner.example'],
    'trailing slash' => ['https://partner.example/'],
    'path' => ['https://partner.example/path'],
    'query' => ['https://partner.example?x=1'],
    'fragment' => ['https://partner.example#x'],
    'userinfo' => ['https://user@partner.example'],
    'userinfo with password' => ['https://user:pass@partner.example'],
    'port zero' => ['https://partner.example:0'],
    'port too high' => ['https://partner.example:65536'],
    'port too long' => ['https://partner.example:123456'],
    'port leading zero' => ['https://partner.example:0443'],
    'empty port' => ['https://partner.example:'],
    'port not digits' => ['https://partner.example:80a'],
    'second port' => ['https://partner.example:1:2'],
    'trailing dot host' => ['https://partner.example.'],
    'underscore host' => ['https://par_tner.example'],
    'semicolon' => ['https://a.test; script-src *'],
    'semicolon glued' => ['https://a.test;'],
    'comma' => ['https://a.test,https://b.test'],
    'backslash' => ['https://a.test\\@evil.test'],
    'space' => ['https://a.test b.test'],
    'leading space' => [' https://a.test'],
    'trailing space' => ['https://a.test '],
    'tab' => ["https://a.test\t"],
    'newline' => ["https://a.test\n"],
    'carriage return line feed' => ["https://a.test\r\nSet-Cookie: x=1"],
    'nul byte' => ["https://a.test\0"],
    'non-ascii host' => ['https://pärtner.example'],
    'non-ascii lookalike' => ["https://partner.exam\u{200B}ple"],
    'quote' => ["https://a.test'"],
    'empty' => [''],
    'null' => [null],
    'int' => [42],
    'array' => [['https://a.test']],
    'bool' => [true],
]);

it('refuses an origin whose host looks like an injected directive', function (): void {
    expect(PartnerOrigin::isValid("https://a.test 'unsafe-inline'"))->toBeFalse()
        ->and(PartnerOrigin::isValid('https://a.test *'))->toBeFalse()
        ->and(PartnerOrigin::isValid('https://a.test:*'))->toBeFalse();
});

it('refuses a whole list when one entry is bad, naming a printable form of it', function (): void {
    expect(PartnerOrigin::normaliseAll(['https://a.test', 'HTTPS://A.test', 'https://*.b.test']))->toBe(['https://a.test', 'https://*.b.test']);

    try {
        PartnerOrigin::normaliseAll(['https://a.test', "https://x.test\r\nX: y"]);
        $this->fail('a bad entry was accepted');
    } catch (InvalidPartnerException $e) {
        expect($e->getMessage())->not->toContain("\r")->not->toContain("\n");
    }
});

it('keeps only the valid entries of a stored value, and nothing for a value that is not a list', function (): void {
    expect(PartnerOrigin::validOnly([
        'https://a.test', 'https://a.test; script-src *', '*', ' https://b.test', "https://c.test\r\n", 42, null, ['x'], 'HTTPS://D.test', 'https://a.test',
    ]))->toBe(['https://a.test', 'https://d.test']);

    expect(PartnerOrigin::validOnly(['https://a.test; script-src *']))->toBe([])
        ->and(PartnerOrigin::validOnly([]))->toBe([])
        ->and(PartnerOrigin::validOnly(null))->toBe([])
        ->and(PartnerOrigin::validOnly('https://a.test'))->toBe([])
        ->and(PartnerOrigin::validOnly(42))->toBe([]);
});
