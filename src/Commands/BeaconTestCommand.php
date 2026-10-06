<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Hei\ScarlettPlayer\Http\Middleware\ScarlettApiKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts a synthetic view (viewStart, heartbeat, reconnecting, recovered, viewEnd)
 * through each key path the player uses: the X-API-Key header (fetch) and the api_key
 * query parameter (the unload sendBeacon, which cannot carry a header). Every one must
 * answer 204. The header view is VOD (a 60 second media duration) and ends
 * `abandoned`; the query view is live and ends `liveEnded`. Bitrates are null, as player 1.22.0 sends them until a quality
 * change reports one.
 *
 * The beacons are real: they are queued and stored like any other, under a view id
 * starting with `scarlett-beacon-test-`. This proves the route, the key and the
 * body from a server-side client. It does not prove browser CORS.
 */
class BeaconTestCommand extends Command
{
    public const VIEW_PREFIX = 'scarlett-beacon-test-';

    protected $signature = 'scarlett:beacon:test
        {--url= : The beacon URL to post to (default: the scarlett.beacons.store route)}';

    protected $description = 'Post test beacons through the header and query-string key paths and expect 204 from each';

    public function handle(Repository $config, UrlGenerator $url, Factory $http): int
    {
        $key = $config->get('scarlett-player.beacons.key');

        if (! is_string($key) || $key === '') {
            $this->error('beacons.key is empty: set SCARLETT_BEACON_KEY first.');

            return self::FAILURE;
        }

        $option = $this->option('url');
        $target = is_string($option) && $option !== '' ? $option : $url->route('scarlett.beacons.store');

        if (! str_starts_with($target, 'https://')) {
            $this->warn("[{$target}] is not https: the analytics plugin only sends its key to an https beaconUrl.");
        }

        $rows = [];
        $failed = false;

        foreach (['header' => false, 'query' => true] as $transport => $live) {
            $viewId = self::VIEW_PREFIX.Str::lower(Str::random(12));
            $timestamp = (int) floor(microtime(true) * 1000);

            foreach ($this->beacons($viewId, $timestamp, $live) as $body) {
                $request = $http->asJson()->timeout(10);
                $endpoint = $target;

                if ($transport === 'header') {
                    $request = $request->withHeaders([ScarlettApiKey::HEADER => $key]);
                } else {
                    $endpoint .= (str_contains($target, '?') ? '&' : '?').ScarlettApiKey::QUERY.'='.rawurlencode($key);
                }

                try {
                    $status = $request->post($endpoint, $body)->status();
                } catch (Throwable $e) {
                    $status = 0;
                    $this->error("{$transport} {$body['event']}: ".$e->getMessage());
                }

                $failed = $failed || $status !== 204;
                $rows[] = [$transport, $body['event'], $viewId, $status === 0 ? 'no response' : (string) $status, $status === 204 ? 'ok' : 'FAIL'];
            }
        }

        $this->table(['Key path', 'Event', 'View', 'Status', 'Result'], $rows);
        $this->line('This proves the route, the key and the body from a server-side client. It does not prove browser CORS:');
        $this->line('the unload beacon also needs the credentialed CORS recipe (README), which only a browser enforces.');

        if ($failed) {
            $this->error('One or more beacons were not answered 204.');

            return self::FAILURE;
        }

        $this->info('Every beacon was answered 204.');

        return self::SUCCESS;
    }

    /**
     * One view in the player 1.22.0 shape: counters on the heartbeat and viewEnd, a
     * reconnect outage, null bitrates, and on a live view `liveEnded`, a null
     * completionRate and dvrTime.
     *
     * @return list<array<string, mixed>>
     */
    private function beacons(string $viewId, int $timestamp, bool $live): array
    {
        $context = [
            'viewId' => $viewId,
            'sessionId' => $viewId,
            'viewerId' => $viewId,
            'videoId' => 'scarlett-beacon-test',
            'videoTitle' => 'scarlett:beacon:test',
            'isLive' => $live,
            'playerVersion' => 'beacon-test',
            'playerName' => 'scarlett-player',
            'browser' => 'artisan',
            'os' => PHP_OS_FAMILY,
            'deviceType' => 'unknown',
            'screenSize' => '0x0',
            'playerSize' => '0x0',
            'connectionType' => 'unknown',
        ];

        $metrics = fn (int $watchTime): array => [
            'watchTime' => $watchTime, 'playTime' => 0, 'rebufferCount' => 0, 'rebufferDuration' => 0,
            'reconnectCount' => 1, 'reconnectDuration' => 1, 'avgBitrate' => null, 'maxBitrate' => null,
            'qualityChanges' => 0, 'pauseCount' => 0, 'pauseDuration' => 0, 'seekCount' => 0,
            'elementSeekCount' => 0, 'errorCount' => 0, 'warningCount' => 0, 'qoeScore' => 100, 'qoeVersion' => 2,
            ...($live ? ['dvrTime' => 0] : []),
        ];

        return [
            ['event' => 'viewStart', 'timestamp' => $timestamp, ...$context],
            // duration is seconds; a live HLS source reports 0, which the store ignores.
            ['event' => 'heartbeat', 'timestamp' => $timestamp + 1, ...$context, ...$metrics(1), 'currentTime' => 0, 'duration' => $live ? 0 : 60],
            ['event' => 'reconnecting', 'timestamp' => $timestamp + 2, ...$context, 'reconnectCount' => 1, 'attempt' => 1],
            ['event' => 'recovered', 'timestamp' => $timestamp + 3, ...$context, 'duration' => 1, 'reconnectCount' => 1, 'attempt' => 1],
            ['event' => 'viewEnd', 'timestamp' => $timestamp + 4, ...$context, ...$metrics(4), 'startupTime' => null, 'rebufferRatio' => 0,
                'exitType' => $live ? 'liveEnded' : 'abandoned', 'completionRate' => $live ? null : 0],
        ];
    }
}
