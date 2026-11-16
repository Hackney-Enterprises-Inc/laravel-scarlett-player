<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Partners\PartnerBeaconContext;
use Hei\ScarlettPlayer\Partners\PartnerGate;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Illuminate\Http\Request;

/*
 * The duplicate-parameter guards are defence in depth: the framework signature covers
 * every raw pair, so over HTTP a repeat normally fails the signature before a guard runs.
 * These tests call the guard branches themselves, with no signature in the way, so each
 * can fail on its own. Both guards name parameters the way PHP's query parser does.
 */

function guardRequest(string $query): Request
{
    return Request::create('/x?'.$query);
}

const GUARD_NAMES = [PartnerParams::REF, PartnerParams::MEDIA, 'expires'];

it('reports a repeated or array proof name, whatever its spelling', function (string $query, bool $repeated): void {
    expect(PartnerBeaconContext::repeated(guardRequest($query), GUARD_NAMES))->toBe($repeated);
})->with([
    'single of each' => ['scp_ref=acme&scp_media=v&expires=1', false],
    'unrelated repeat' => ['a=1&a=2&scp_ref=acme', false],
    'repeated ref' => ['scp_ref=evil&scp_ref=acme', true],
    'repeated media' => ['scp_media=a&scp_media=b', true],
    'repeated expires' => ['expires=1&expires=2', true],
    'array ref' => ['scp_ref[]=evil&scp_ref=acme', true],
    'keyed array media' => ['scp_media[k]=x', true],
    'dotted alias' => ['scp.ref=evil&scp_ref=acme', true],
    'spaced alias' => ['scp%20ref=evil&scp_ref=acme', true],
    'encoded name' => ['scp%5Fref=evil&scp_ref=acme', true],
    'leading space' => ['%20scp_ref=evil&scp_ref=acme', true],
]);

it('counts scp_key pairs by the same name rule', function (string $query, int $count): void {
    expect(PartnerGate::keyCount(guardRequest($query)))->toBe($count);
})->with([
    'none' => ['expires=1', 0],
    'one' => ['scp_key=abc&expires=1', 1],
    'repeated' => ['scp_key=a&scp_key=b', 2],
    'dotted alias' => ['scp.key=a&scp_key=b', 2],
    'spaced alias' => ['scp%20key=a', 1],
    'encoded name' => ['scp%5Fkey=a&scp_key=b', 2],
    'no value' => ['scp_key&scp_key=b', 2],
    'empty pairs' => ['&&scp_key=a&', 1],
]);

it('gives a raw pair the name the query parser gives it', function (): void {
    foreach (['scp.ref=1', 'scp%20ref=1', '%20scp_ref=1', 'scp%5Fref=1', 'scp_ref', 'scp_ref=a=b'] as $pair) {
        expect(PartnerParams::queryName($pair))->toBe('scp_ref');

        parse_str($pair, $parsed);
        expect(array_key_first($parsed))->toBe('scp_ref');
    }
});
