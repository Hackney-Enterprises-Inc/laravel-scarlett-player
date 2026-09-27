<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Clips;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Process;

/**
 * The rights boundary: reads the packet timestamps of every stream of a rendered file with
 * ffprobe and refuses anything longer than was asked for.
 *
 * It measures the packet span (max pts minus min pts), never the container duration:
 * a keyframe copy of [9, 69] carries 69 s of video in a file whose nominal duration is
 * about 60 s, with the extra nine seconds at negative timestamps.
 */
class ClipVerifier
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Check a rendered file against the requested interval.
     *
     * Passes when there is at least one video packet, the earliest sits at or after
     * -duration_tolerance relative to the container start, the span is at most
     * (end - start) + duration_tolerance, and for a protected source also at most
     * max_duration + duration_tolerance.
     */
    public function verify(string $path, float $start, float $end, bool $isProtected): Verification
    {
        $tolerance = (float) $this->config->get('scarlett-player.clips.duration_tolerance', 1.0);
        $limit = ($end - $start) + $tolerance;

        if ($isProtected) {
            $limit = min($limit, (float) $this->config->get('scarlett-player.clips.max_duration', 60) + $tolerance);
        }

        $probe = $this->probe($path);

        if ($probe === null) {
            return new Verification(false, Verification::REASON_UNREADABLE, null, null, $limit, 'ffprobe found no video packets.');
        }

        [$earliest, $span] = $probe;

        if ($earliest < -$tolerance) {
            return new Verification(false, Verification::REASON_EXCEEDS_BOUNDS, $earliest, $span, $limit,
                sprintf('Earliest packet at %.3F s, before the container start.', $earliest));
        }

        if ($span > $limit) {
            return new Verification(false, Verification::REASON_EXCEEDS_BOUNDS, $earliest, $span, $limit,
                sprintf('Packet span %.3F s exceeds the %.3F s allowed.', $span, $limit));
        }

        return new Verification(true, null, $earliest, $span, $limit);
    }

    /**
     * Earliest packet of any stream (relative to the container start) and the packet
     * span across all streams, or null when the file has no readable video packets.
     *
     * @return array{0: float, 1: float}|null
     */
    public function probe(string $path): ?array
    {
        $result = Process::timeout(120)->run([
            $this->binary(), '-v', 'error',
            '-show_entries', 'packet=stream_index,pts_time:stream=index,codec_type:format=start_time',
            '-of', 'json', $path,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $data = json_decode($result->output(), true);

        if (! is_array($data) || ! is_array($data['packets'] ?? null) || ! is_array($data['streams'] ?? null)) {
            return null;
        }

        $video = [];

        foreach ($data['streams'] as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video' && isset($stream['index'])) {
                $video[] = (int) $stream['index'];
            }
        }

        // A file with no video stream is not a clip, whatever else it carries.
        if ($video === []) {
            return null;
        }

        $times = [];
        $videoPackets = 0;

        foreach ($data['packets'] as $packet) {
            $pts = is_array($packet) ? ($packet['pts_time'] ?? null) : null;

            if (! is_numeric($pts)) {
                continue;
            }

            // Every stream counts: a driver cannot hide extra material in audio or data.
            $times[] = (float) $pts;
            $videoPackets += in_array((int) ($packet['stream_index'] ?? -1), $video, true) ? 1 : 0;
        }

        if ($videoPackets === 0 || $times === []) {
            return null;
        }

        $containerStart = $data['format']['start_time'] ?? 0;
        $containerStart = is_numeric($containerStart) ? (float) $containerStart : 0.0;

        $min = min($times);

        return [$min - $containerStart, max($times) - $min];
    }

    /**
     * The ffprobe binary of the configured generator.
     */
    public function binary(): string
    {
        $generator = (string) $this->config->get('scarlett-player.clips.generator', 'local-ffmpeg');
        $binary = $this->config->get("scarlett-player.clips.generators.{$generator}.ffprobe");

        return is_string($binary) && $binary !== '' ? $binary : 'ffprobe';
    }
}
