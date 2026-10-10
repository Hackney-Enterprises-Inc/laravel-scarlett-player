<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Beacons\GaugeNormalizer;

/*
 * The pure gauge normalizer: canonical 0..1 ratios with value, availability and
 * a reason. A recognized explicit marker wins; an invalid one never falls back
 * to guessed units; an absent marker is decided only by the audited
 * producer/version registry. Magnitude never establishes units.
 */

it('normalizes an explicit percent marker, dividing low percentages by 100 too', function (mixed $value, ?float $expected): void {
    $reading = GaugeNormalizer::normalize($value, true, 'percent', 'scarlett-player', '1.22.0');

    expect($reading->available)->toBe($expected !== null)
        ->and($reading->value)->toBe($expected)
        ->and($reading->reason)->toBe('marker_percent'.($expected !== null && ($value / 100) > 1 ? '_clamped' : ''));
})->with([
    'whole' => [100, 1.0],
    'typical' => [37.5, 0.375],
    'low percent stays a percent' => [0.5, 0.005],
    'one percent is not a ratio' => [1, 0.01],
    'zero' => [0, 0.0],
    'integer' => [15, 0.15],
]);

it('passes an explicit ratio marker through unchanged', function (mixed $value, ?float $expected): void {
    $reading = GaugeNormalizer::normalize($value, true, 'ratio', 'scarlett-player', '1.22.0');

    expect($reading->available)->toBe($expected !== null)
        ->and($reading->value)->toBe($expected)
        ->and($reading->reason)->toBe('marker_ratio');
})->with([
    'half' => [0.5, 0.5],
    'zero' => [0, 0.0],
    'whole' => [1, 1.0],
    'low value stays a ratio' => [0.005, 0.005],
]);

it('clamps finite out-of-range inputs and exposes the clamp in the reason', function (): void {
    $over = GaugeNormalizer::normalize(150, true, 'percent', 'scarlett-player', '1.22.0');
    expect($over->available)->toBeTrue()
        ->and($over->value)->toBe(1.0)
        ->and($over->reason)->toBe('marker_percent_clamped');

    $under = GaugeNormalizer::normalize(-5, true, 'percent', 'scarlett-player', '1.22.0');
    expect($under->available)->toBeTrue()
        ->and($under->value)->toBe(0.0)
        ->and($under->reason)->toBe('marker_percent_clamped');

    $ratio = GaugeNormalizer::normalize(1.5, true, 'ratio', 'other-player', '9.9.9');
    expect($ratio->available)->toBeTrue()
        ->and($ratio->value)->toBe(1.0)
        ->and($ratio->reason)->toBe('marker_ratio_clamped');
});

it('never falls back to guessed units for an explicitly invalid marker', function (mixed $marker): void {
    $reading = GaugeNormalizer::normalize(37.5, true, $marker, 'scarlett-player', '1.22.0');

    expect($reading->available)->toBeFalse()
        ->and($reading->value)->toBeNull()
        ->and($reading->reason)->toBe('invalid_marker');
})->with([
    'another string' => ['fractions'],
    'upper case is not the marker' => ['Percent'],
    'a number' => [100],
    'a boolean' => [true],
    'an array' => [['percent']],
    'null present' => [null],
]);

it('uses the audited registry when the marker is absent', function (): void {
    $reading = GaugeNormalizer::normalize(37.5, false, null, 'scarlett-player', '1.22.0');

    expect($reading->available)->toBeTrue()
        ->and($reading->value)->toBe(0.375)
        ->and($reading->reason)->toBe('registry_percent');
});

it('leaves the scale unknown for producers or versions outside the registry', function (?string $name, ?string $version): void {
    $reading = GaugeNormalizer::normalize(37.5, false, null, $name, $version);

    expect($reading->available)->toBeFalse()
        ->and($reading->value)->toBeNull()
        ->and($reading->reason)->toBe('unknown_scale');
})->with([
    'another producer' => ['other-player', '1.22.0'],
    'an unaudited version' => ['scarlett-player', '1.21.0'],
    'no version' => ['scarlett-player', null],
    'no producer' => [null, '1.22.0'],
    'a numeric version is not the audited string' => ['scarlett-player', '1.22'],
]);

it('never infers units from magnitude', function (mixed $value): void {
    $reading = GaugeNormalizer::normalize($value, false, null, 'scarlett-player', '1.19.1');

    expect($reading->available)->toBeFalse()
        ->and($reading->reason)->toBe('unknown_scale');
})->with([0.5, 15.14, 100, 0.001]);

it('treats non-finite and non-numeric values as unavailable even with a marker', function (mixed $value): void {
    $reading = GaugeNormalizer::normalize($value, true, 'percent', 'scarlett-player', '1.22.0');

    expect($reading->available)->toBeFalse()
        ->and($reading->value)->toBeNull()
        ->and($reading->reason)->toBe('nonfinite');
})->with([
    'infinity' => [INF],
    'negative infinity' => [-INF],
    'not a number' => [NAN],
    'a string' => ['37.5'],
    'a boolean' => [true],
    'an array' => [[37.5]],
]);

it('reports an explicit null measurement as processed unavailable', function (): void {
    $reading = GaugeNormalizer::normalize(null, true, 'percent', 'scarlett-player', '1.22.0');

    expect($reading->available)->toBeFalse()
        ->and($reading->value)->toBeNull()
        ->and($reading->reason)->toBe('explicit_null');
});

it('does not let the registry override a recognized marker', function (): void {
    $reading = GaugeNormalizer::normalize(0.5, true, 'ratio', 'scarlett-player', '1.22.0');

    expect($reading->value)->toBe(0.5)
        ->and($reading->reason)->toBe('marker_ratio');
});
