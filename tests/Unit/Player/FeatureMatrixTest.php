<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\InvalidPlayerConfigException;
use Hei\ScarlettPlayer\Player\FeatureMatrix;

it('supports every feature in module mode', function (string $feature): void {
    expect(FeatureMatrix::supports($feature, 'module', '1.20.0'))->toBeTrue();
})->with(array_keys(FeatureMatrix::FEATURES));

it('says no for the live flag and the heartbeat interval in embed mode', function (string $feature): void {
    expect(FeatureMatrix::supports($feature, 'embed', '1.17.0'))->toBeFalse()
        ->and(FeatureMatrix::cell($feature, 'embed'))->toBeFalse();
})->with(['analytics_live', 'analytics_heartbeat']);

it('supports clips, chapters and captions in embed mode from player 1.17.0 only', function (string $feature): void {
    expect(FeatureMatrix::cell($feature, 'embed'))->toBe('1.17.0')
        ->and(FeatureMatrix::supports($feature, 'embed', '1.17.0'))->toBeTrue()
        ->and(FeatureMatrix::supports($feature, 'embed', '1.18.0'))->toBeTrue()
        ->and(FeatureMatrix::supports($feature, 'embed', '1.16.3'))->toBeFalse();
})->with(['clips', 'chapters', 'captions']);

it('says yes for playback, brand, analytics and share in embed mode', function (string $feature): void {
    expect(FeatureMatrix::supports($feature, 'embed', '1.16.2'))->toBeTrue();
})->with(['playback', 'brand', 'analytics', 'share']);

it('reads a version cell as supported from that player version', function (): void {
    expect(FeatureMatrix::cellSupports('1.17.0', '1.17.0'))->toBeTrue()
        ->and(FeatureMatrix::cellSupports('1.17.0', '1.18.1'))->toBeTrue()
        ->and(FeatureMatrix::cellSupports('1.17.0', '1.16.2'))->toBeFalse()
        ->and(FeatureMatrix::cellSupports(true, '0.1.0'))->toBeTrue()
        ->and(FeatureMatrix::cellSupports(false, '9.0.0'))->toBeFalse();
});

it('throws for an unknown feature or mode', function (string $feature, string $mode): void {
    FeatureMatrix::cell($feature, $mode);
})->with([
    ['watermark', 'module'],
    ['clips', 'iframe'],
])->throws(InvalidPlayerConfigException::class);

it('renders the README table from the same rows', function (): void {
    $markdown = FeatureMatrix::toMarkdown();
    $lines = explode("\n", $markdown);

    expect($lines)->toHaveCount(2 + count(FeatureMatrix::FEATURES))
        ->and($lines[0])->toStartWith('| Feature | `module`')
        ->and($markdown)->toContain('| clips (endpoint, CSRF header) | yes | yes, from player 1.17.0; `data-clips-endpoint`')
        ->and($markdown)->toContain('| brand colour / brand text colour | yes | yes; `data-brand-color`, `data-brand-text-color` |');
});

it('keeps the README matrix identical to the generated one', function (): void {
    $readme = (string) file_get_contents(__DIR__.'/../../../README.md');

    expect($readme)->toContain(
        "<!-- feature-matrix:start -->\n".FeatureMatrix::toMarkdown()."\n<!-- feature-matrix:end -->"
    );
});

it('supports the heartbeat interval in module mode only on the pinned player', function (): void {
    expect(FeatureMatrix::supports('analytics_heartbeat', 'module', '1.22.0'))->toBeTrue()
        ->and(FeatureMatrix::cell('analytics_heartbeat', 'embed'))->toBeFalse()
        ->and(FeatureMatrix::toMarkdown())->toContain('no `data-analytics-heartbeat-interval` attribute; the player default applies');
});
