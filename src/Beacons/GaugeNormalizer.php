<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Beacons;

/**
 * The pure normalization of the wire gauges (completionRate, rebufferRatio)
 * into canonical 0..1 ratios.
 *
 * The released Scarlett 1.x wire sends percentages and keeps that meaning; the
 * canonical columns store ratios. A gauge's units come only from:
 *
 * 1. A recognized explicit marker: the beacon's own `gaugeScale`, read by the
 *    store from the post-pipeline custom dimensions as an exact whitelisted
 *    string ('percent' or 'ratio'). A marker that is present but not one of
 *    those strings, an explicit null included, is an explicit invalid marker: the measurement is
 *    unavailable, and the normalizer never falls back to guessed units.
 * 2. An absent marker: the source-audited producer/version registry below.
 *    The view row's set-once player_version and newer cumulative counters are
 *    never evidence for a later beacon's gauge; only the beacon's own
 *    playerName and playerVersion are asked.
 *
 * A value's magnitude never establishes its units: 0.5 can be 0.5% or 50%.
 * Percent divides by 100 even when the value is below one; ratio passes
 * unchanged. Finite results clamp to 0..1, with the reason exposing the
 * out-of-range input. Unavailable measurements keep the measurement's own
 * stamp at the store, so a processed null is distinguishable from a gauge
 * that was never measured.
 */
final class GaugeNormalizer
{
    public const SCALE_PERCENT = 'percent';

    public const SCALE_RATIO = 'ratio';

    public const REASON_MARKER_PERCENT = 'marker_percent';

    public const REASON_MARKER_RATIO = 'marker_ratio';

    public const REASON_REGISTRY_PERCENT = 'registry_percent';

    public const REASON_EXPLICIT_NULL = 'explicit_null';

    public const REASON_INVALID_MARKER = 'invalid_marker';

    public const REASON_UNKNOWN_SCALE = 'unknown_scale';

    public const REASON_NONFINITE = 'nonfinite';

    /** Suffix appended to the scale reason when a finite input clamps. */
    public const REASON_CLAMPED = '_clamped';

    /**
     * Producer/version registry, proven by source audit; extend only with
     * evidence, never by magnitude.
     *
     * - scarlett-player 1.22.0: @scarlett-player/analytics 1.22.0 builds both
     *   gauges in viewEndMetrics() as percent (currentTime/duration * 100 and
     *   rebufferDuration/watchTime * 100; 100 for a completed VOD, null only
     *   for live). It sends no gaugeScale and does not bound the result: an
     *   unknown duration falls back to the last known duration and position
     *   or sends 0, a position past the duration exceeds 100, and rebuffering
     *   longer than watch time exceeds 100 (zero watch time sends 0). Verified by running
     *   viewEndMetrics() from the pinned npm 1.22.0 bundle and against the
     *   derived 1.22.0 wire fixtures (completionRate 37.5); out-of-range
     *   values clamp here with a `_clamped` reason.
     *
     * Other versions of the same plugin sent the same formulas historically,
     * but they are not audited here and stay unknown until evidence lands.
     *
     * @var array<string, array<string, self::SCALE_*>>
     */
    private const PRODUCER_SCALES = [
        'scarlett-player' => [
            '1.22.0' => self::SCALE_PERCENT,
        ],
    ];

    /**
     * Normalize one gauge measurement.
     *
     * @param  mixed  $value  the wire value; null is an explicit-null measurement
     * @param  bool  $markerPresent  the beacon carried a `gaugeScale` key
     * @param  mixed  $marker  the carried marker's value when present
     * @param  mixed  $playerName  the beacon's own playerName
     * @param  mixed  $playerVersion  the beacon's own playerVersion
     */
    public static function normalize(mixed $value, bool $markerPresent, mixed $marker, mixed $playerName, mixed $playerVersion): GaugeReading
    {
        if ($value === null) {
            return new GaugeReading(null, false, self::REASON_EXPLICIT_NULL);
        }

        $scale = self::scale($markerPresent, $marker, $playerName, $playerVersion);

        if ($scale === null) {
            return new GaugeReading(
                null,
                false,
                $markerPresent ? self::REASON_INVALID_MARKER : self::REASON_UNKNOWN_SCALE,
            );
        }

        [$reason, $reasonScale] = $scale;

        if (! is_int($value) && ! is_float($value)) {
            return new GaugeReading(null, false, self::REASON_NONFINITE);
        }

        if (! is_finite((float) $value)) {
            return new GaugeReading(null, false, self::REASON_NONFINITE);
        }

        $canonical = $reasonScale === self::SCALE_PERCENT ? ((float) $value) / 100 : (float) $value;
        $clamped = $canonical < 0.0 || $canonical > 1.0;

        return new GaugeReading(
            min(1.0, max(0.0, $canonical)),
            true,
            $reason.($clamped ? self::REASON_CLAMPED : ''),
        );
    }

    /**
     * The units a gauge's value is in, from the marker or the registry.
     *
     * @return array{0: string, 1: self::SCALE_*}|null [reason, scale], or null when unknown/invalid
     */
    private static function scale(bool $markerPresent, mixed $marker, mixed $playerName, mixed $playerVersion): ?array
    {
        // A recognized explicit marker wins over the registry.
        if ($markerPresent) {
            if ($marker === self::SCALE_PERCENT) {
                return [self::REASON_MARKER_PERCENT, self::SCALE_PERCENT];
            }

            if ($marker === self::SCALE_RATIO) {
                return [self::REASON_MARKER_RATIO, self::SCALE_RATIO];
            }

            return null;
        }

        if (! is_string($playerName) || ! is_string($playerVersion)) {
            return null;
        }

        $versions = self::PRODUCER_SCALES[$playerName] ?? null;

        if ($versions === null) {
            return null;
        }

        $scale = $versions[$playerVersion] ?? null;

        return $scale === null ? null : [self::REASON_REGISTRY_PERCENT, $scale];
    }
}
