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
 * Posts a synthetic viewStart, heartbeat and viewEnd through each key path the
 * player uses: the X-API-Key header (fetch) and the api_key query parameter (the
 * unload sendBeacon, which cannot carry a header). Every one must answer 204.
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

        foreach (['header', 'query'] as $transport) {
            $viewId = self::VIEW_PREFIX.Str::lower(Str::random(12));
            $timestamp = (int) floor(microtime(true) * 1000);

            foreach ($this->beacons($viewId, $timestamp) as $body) {
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
     * @return list<array<string, mixed>>
     */
    private function beacons(string $viewId, int $timestamp): array
    {
        $context = [
            'viewId' => $viewId,
            'sessionId' => $viewId,
            'viewerId' => $viewId,
            'videoId' => 'scarlett-beacon-test',
            'videoTitle' => 'scarlett:beacon:test',
            'isLive' => false,
            'playerVersion' => 'beacon-test',
            'playerName' => 'scarlett-player',
            'browser' => 'artisan',
            'os' => PHP_OS_FAMILY,
            'deviceType' => 'unknown',
            'screenSize' => '0x0',
            'playerSize' => '0x0',
            'connectionType' => 'unknown',
        ];

        return [
            ['event' => 'viewStart', 'timestamp' => $timestamp, ...$context],
            ['event' => 'heartbeat', 'timestamp' => $timestamp + 1, ...$context, 'watchTime' => 1, 'playTime' => 0, 'rebufferCount' => 0, 'rebufferDuration' => 0, 'avgBitrate' => 0, 'qoeScore' => 100],
            ['event' => 'viewEnd', 'timestamp' => $timestamp + 2, ...$context, 'watchTime' => 2, 'playTime' => 0, 'startupTime' => null, 'rebufferCount' => 0, 'rebufferDuration' => 0, 'avgBitrate' => 0, 'maxBitrate' => 0, 'exitType' => 'abandoned'],
        ];
    }
}
