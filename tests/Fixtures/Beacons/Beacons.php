<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Beacon bodies shaped like the ones @scarlett-player/analytics 1.17.0 builds
 * (index.ts sendBeacon() and sendUnloadBeacon()): the context keys on every beacon,
 * then the event keys. For the merge tests; the wire fixtures under
 * tests/Fixtures/wire/1.17.0/ (captured) are the per-transport reference bodies.
 */
final class Beacons
{
    public const VIEW = 'view-1';

    public const T0 = 1_790_000_000_000;

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function body(string $event, int $offsetMs = 0, array $extra = [], string $viewId = self::VIEW): array
    {
        // array_replace, not `...`: spreading renumbers numeric-string keys.
        return array_replace([
            'event' => $event,
            'timestamp' => self::T0 + $offsetMs,
            'viewId' => $viewId,
            'sessionId' => 'session-1',
            'viewerId' => 'viewer-1',
            'videoId' => 'video-1',
            'videoTitle' => 'A video',
            'isLive' => false,
            'playerVersion' => '1.17.0',
            'playerName' => 'scarlett-player',
            'browser' => 'Chrome',
            'os' => 'macOS',
            'deviceType' => 'desktop',
            'screenSize' => '1920x1080',
            'playerSize' => '1280x720',
            'connectionType' => '4g',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function payload(string $event, int $offsetMs = 0, array $extra = [], string $viewId = self::VIEW): BeaconPayload
    {
        return BeaconPayload::fromArray(self::body($event, $offsetMs, $extra, $viewId));
    }

    /**
     * A heartbeat with the plugin's heartbeat keys.
     */
    public static function heartbeat(int $offsetMs, int $watchTime, float $qoeScore, int $rebufferCount = 0): BeaconPayload
    {
        return self::payload('heartbeat', $offsetMs, [
            'watchTime' => $watchTime,
            'playTime' => $watchTime,
            'currentTime' => $watchTime / 1000,
            'duration' => 600,
            'rebufferCount' => $rebufferCount,
            'rebufferDuration' => $rebufferCount * 100,
            'avgBitrate' => 2_500_000,
            'qoeScore' => $qoeScore,
        ]);
    }

    /**
     * The viewEnd sendViewEnd() builds on playback:ended or destroy: every key.
     */
    public static function endedViewEnd(int $offsetMs, int $watchTime, float $qoeScore, float $completionRate): BeaconPayload
    {
        return self::payload('viewEnd', $offsetMs, [
            'watchTime' => $watchTime,
            'playTime' => $watchTime,
            'startupTime' => 420,
            'rebufferCount' => 1,
            'rebufferDuration' => 100,
            'rebufferRatio' => 0.5,
            'avgBitrate' => 2_500_000,
            'maxBitrate' => 5_000_000,
            'qualityChanges' => 2,
            'pauseCount' => 1,
            'pauseDuration' => 3000,
            'seekCount' => 1,
            'errorCount' => 0,
            'exitType' => 'completed',
            'qoeScore' => $qoeScore,
            'completionRate' => $completionRate,
        ]);
    }

    /**
     * The viewEnd onBeforeUnload() sends through sendBeacon: the subset, with no
     * qoeScore, rebufferRatio, qualityChanges, pauseCount, pauseDuration, seekCount,
     * errorCount or completionRate.
     */
    public static function unloadViewEnd(int $offsetMs, int $watchTime): BeaconPayload
    {
        return self::payload('viewEnd', $offsetMs, [
            'watchTime' => $watchTime,
            'playTime' => $watchTime,
            'startupTime' => 420,
            'rebufferCount' => 1,
            'rebufferDuration' => 100,
            'avgBitrate' => 2_400_000,
            'maxBitrate' => 5_000_000,
            'exitType' => 'abandoned',
        ]);
    }
}
