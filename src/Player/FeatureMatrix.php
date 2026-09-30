<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Player;

use Hei\ScarlettPlayer\Exceptions\InvalidPlayerConfigException;

/**
 * What each integration mode can carry: the one table PlayerConfigBuilder enforces and
 * the README matrix is generated from, so the two cannot drift.
 *
 * A cell is true (supported), false (not supported), or a player version string,
 * meaning "supported from that @scarlett-player/* version". Flipping an embed row
 * once the player documents the attributes is a change to that cell only.
 */
final class FeatureMatrix
{
    public const MODULE = 'module';

    public const EMBED = 'embed';

    public const MODES = [self::MODULE, self::EMBED];

    /**
     * Features the embed's Full and Video builds carry and its Audio build does not
     * (embed.audio.*): the builder refuses them in embed mode on that build.
     */
    public const VIDEO_BUILD_ONLY = ['clips', 'chapters', 'captions'];

    /**
     * @var array<string, array{label: string, module: bool|string, embed: bool|string, module_note: string, embed_note: string}>
     */
    public const FEATURES = [
        'playback' => [
            'label' => 'src, poster, autoplay, muted, loop, start time',
            'module' => true,
            'embed' => true,
            'module_note' => '',
            'embed_note' => '',
        ],
        'brand' => [
            'label' => 'brand colour / brand text colour',
            'module' => true,
            'embed' => true,
            'module_note' => '',
            'embed_note' => '`data-brand-color`, `data-brand-text-color`',
        ],
        'analytics' => [
            'label' => 'analytics (beaconUrl, videoId, apiKey)',
            'module' => true,
            'embed' => true,
            'module_note' => 'plus `headers()` in the initialiser',
            'embed_note' => '`data-analytics-*`, no extra headers',
        ],
        'analytics_privacy' => [
            'label' => 'analytics anonymous IDs and DNT/GPC',
            'module' => '1.20.0',
            'embed' => '1.20.0',
            'module_note' => '`anonymous`, `respectDoNotTrack`; `beforeSend` through JavaScript options',
            'embed_note' => '`data-analytics-anonymous`, `data-analytics-respect-dnt`; Full build only',
        ],
        'analytics_live' => [
            'label' => 'analytics live flag (isLive from the MediaSource)',
            'module' => true,
            'embed' => false,
            'module_note' => 'the initialiser passes it to the analytics plugin, so viewStart is right before the playlist loads',
            'embed_note' => 'the embed has no attribute for it; from player 1.18 viewStart carries null before the playlist loads, which the ingest treats as absent, so the view is marked live by a later beacon: late, never wrong',
        ],
        'analytics_heartbeat' => [
            'label' => 'analytics heartbeat interval (`player.heartbeat_interval`)',
            'module' => true,
            'embed' => false,
            'module_note' => '',
            'embed_note' => 'no `data-analytics-heartbeat-interval` attribute; the player default applies',
        ],
        'share' => [
            'label' => 'share URL + embed base URL',
            'module' => true,
            'embed' => true,
            'module_note' => '',
            'embed_note' => '`data-share-url`, `data-embed-base-url`',
        ],
        'clips' => [
            'label' => 'clips (endpoint, CSRF header)',
            'module' => true,
            'embed' => '1.17.0',
            'module_note' => '',
            'embed_note' => '`data-clips-endpoint`, `data-clips-csrf="meta"`, `data-clips-media-id`, `data-clips-min-duration`, `data-clips-max-duration`, with the `embed.addon.clips` addon; Full and Video builds only; the host page needs its `csrf-token` meta tag',
        ],
        'chapters' => [
            'label' => 'chapters',
            'module' => true,
            'embed' => '1.17.0',
            'module_note' => '',
            'embed_note' => '`data-chapters` (JSON or a WebVTT URL), with the `embed.addon.chapters` addon; Full and Video builds only',
        ],
        'captions' => [
            'label' => 'captions',
            'module' => true,
            'embed' => '1.17.0',
            'module_note' => '',
            'embed_note' => '`data-captions`, no addon; Full and Video builds only',
        ],
    ];

    /**
     * Whether the mode carries the feature on this player version.
     *
     * @throws InvalidPlayerConfigException for an unknown feature or mode.
     */
    public static function supports(string $feature, string $mode, string $playerVersion): bool
    {
        return self::cellSupports(self::cell($feature, $mode), $playerVersion);
    }

    /**
     * Whether one cell means "supported" on this player version.
     */
    public static function cellSupports(bool|string $cell, string $playerVersion): bool
    {
        return is_string($cell) ? version_compare($playerVersion, $cell, '>=') : $cell;
    }

    /**
     * The raw cell: true, false, or the first player version that supports it.
     *
     * @throws InvalidPlayerConfigException for an unknown feature or mode.
     */
    public static function cell(string $feature, string $mode): bool|string
    {
        $features = self::features();

        if (! isset($features[$feature])) {
            throw InvalidPlayerConfigException::unknownFeature($feature);
        }

        if (! in_array($mode, self::MODES, true)) {
            throw InvalidPlayerConfigException::unknownMode($mode);
        }

        return $features[$feature][$mode];
    }

    /**
     * The table, typed as what a cell may hold rather than what it holds today.
     *
     * @return array<string, array{label: string, module: bool|string, embed: bool|string, module_note: string, embed_note: string}>
     */
    public static function features(): array
    {
        return self::FEATURES;
    }

    /**
     * The README feature matrix, as a Markdown table.
     */
    public static function toMarkdown(): string
    {
        $lines = [
            '| Feature | `module` (host bundles `@scarlett-player/*` + the initialiser) | `embed` (`data-*` attributes + the `@scarlett-player/embed` bundle) |',
            '|---|---|---|',
        ];

        foreach (self::features() as $row) {
            $lines[] = '| '.$row['label'].' | '.self::describe($row['module'], $row['module_note']).' | '.self::describe($row['embed'], $row['embed_note']).' |';
        }

        return implode("\n", $lines);
    }

    private static function describe(bool|string $cell, string $note): string
    {
        $text = match (true) {
            $cell === true => 'yes',
            $cell === false => '**no**',
            default => "yes, from player {$cell}",
        };

        return $note === '' ? $text : "{$text}; {$note}";
    }
}
