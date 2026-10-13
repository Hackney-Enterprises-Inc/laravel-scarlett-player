<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Carbon\CarbonImmutable;
use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;
use stdClass;
use Throwable;

/**
 * Prints what the package stored about a viewer's playback, for support: the
 * matching views, newest first, each with its errors and its raw beacon timeline.
 * Find views by view id, by the host's viewer reference (beacons.viewer_ref_key,
 * copied from the server context), by the player's viewerId or by video, and
 * narrow them by when they were last active.
 *
 * The timeline shows each beacon's client time, the server's receive time and,
 * for player 1.25.0 and later, the gap between receipt and the browser's `sentAt`:
 * network time plus how far the viewer's clock is off. Read only; only the tables
 * EloquentBeaconStore writes.
 */
class InspectViewsCommand extends Command
{
    /** Payload keys worth a glance on a timeline row, in display order. */
    private const DETAIL_KEYS = [
        'exitType', 'errorCode', 'errorSeverity', 'errorMessage', 'httpStatus', 'reconnecting',
        'attempt', 'delayMs', 'startupTime', 'watchTime', 'rebufferCount', 'rebufferDuration',
        'totalRebufferTime', 'reconnectCount', 'seekTo', 'seekSource', 'bitrate', 'width',
        'height', 'droppedFramesTotal', 'decodedFramesTotal', 'currentTime', 'isLive',
    ];

    protected $signature = 'scarlett:views:inspect
        {view? : A view id}
        {--ref= : The host\'s viewer reference (the beacons.viewer_ref_key value, e.g. a user id)}
        {--viewer= : The player\'s viewerId}
        {--video= : A video id}
        {--since= : Only views active since then: 30m, 24h, 7d, or a date}
        {--limit=5 : The most views to show, newest first}
        {--events=100 : Timeline rows per view, 0 for none}
        {--json : Print JSON instead of tables}';

    protected $description = 'Show a viewer\'s Scarlett views with their errors and beacon timeline, for troubleshooting';

    public function handle(Repository $config, ConnectionResolverInterface $database, BeaconStore $store): int
    {
        if (! $store instanceof EloquentBeaconStore) {
            $this->error('The bound beacon store is not the Eloquent store; inspect your own store directly.');

            return self::FAILURE;
        }

        $view = $this->argument('view');
        $ref = $this->option('ref');
        $viewer = $this->option('viewer');
        $video = $this->option('video');

        if (! $this->filled($view) && ! $this->filled($ref) && ! $this->filled($viewer) && ! $this->filled($video)) {
            $this->error('Give a view id, --ref, --viewer or --video.');

            return self::FAILURE;
        }

        $limit = $this->whole($this->option('limit'));
        $events = $this->whole($this->option('events'));

        if ($limit === null || $limit < 1 || $events === null) {
            $this->error('--limit takes a whole number above 0, --events a whole number.');

            return self::FAILURE;
        }

        $since = $this->since($this->option('since'));

        if ($since === false) {
            $this->error('--since takes a duration (30m, 24h, 7d) or a date.');

            return self::FAILURE;
        }

        $connection = $database->connection();

        if (! $connection instanceof Connection) {
            $this->error('Cannot inspect views on this database connection.');

            return self::FAILURE;
        }

        if ($this->filled($ref) && ! $connection->getSchemaBuilder()->hasColumn(EloquentBeaconStore::VIEWS, 'viewer_ref')) {
            $this->error('scarlett_views.viewer_ref does not exist. Publish --tag=scarlett-migrations-diagnostics and migrate first.');

            return self::FAILURE;
        }

        $query = $connection->table(EloquentBeaconStore::VIEWS);

        foreach (['view_id' => $view, 'viewer_ref' => $ref, 'viewer_id' => $viewer, 'video_id' => $video] as $column => $value) {
            if ($this->filled($value)) {
                $query->where($column, (string) $value);
            }
        }

        if ($since !== null) {
            $query->where('updated_at', '>=', $since->utc()->format('Y-m-d H:i:s.v'));
        }

        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get()->all();

        if ($rows === []) {
            $this->warn('No views match.');

            return self::SUCCESS;
        }

        $raw = (bool) $config->get('scarlett-player.beacons.store_raw_events');
        $except = array_values(array_filter((array) $config->get('scarlett-player.beacons.raw_events_except', []), 'is_string'));
        $report = [];

        foreach ($rows as $row) {
            $report[] = [
                'view' => $row,
                'errors' => $connection->table(EloquentBeaconStore::ERRORS)
                    ->where('view_id', $row->view_id)->orderBy('occurred_at')->orderBy('id')->get()->all(),
                'events' => $raw && $events > 0 ? $this->timeline($connection, (string) $row->view_id, $events) : [],
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (array $entry): array => [
                'view' => $this->decodeMaps((array) $entry['view']),
                'errors' => array_map(fn (stdClass $error): array => (array) $error, $entry['errors']),
                'events' => $entry['events'],
            ], $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($report as $entry) {
            $this->printView($entry['view'], $entry['errors'], $entry['events'], $raw, $except, $events);
        }

        return self::SUCCESS;
    }

    /**
     * The view's raw events in client order, each with its receive-to-dispatch gap.
     *
     * @return list<array{occurred_at: string, received_at: string, skew_ms: int|null, event: string, seq: int|null, payload: array<string, mixed>}>
     */
    private function timeline(Connection $connection, string $viewId, int $limit): array
    {
        $rows = $connection->table(EloquentBeaconStore::EVENTS)
            ->where('view_id', $viewId)->orderBy('occurred_at')->orderBy('id')->limit($limit)->get()->all();

        return array_values(array_map(function (stdClass $row): array {
            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $sentAt = $payload['sentAt'] ?? null;

            return [
                'occurred_at' => (string) $row->occurred_at,
                'received_at' => (string) $row->received_at,
                'skew_ms' => is_int($sentAt) || is_float($sentAt) ? $this->milliseconds((string) $row->received_at) - (int) $sentAt : null,
                'event' => (string) $row->event,
                'seq' => isset($row->seq) && is_numeric($row->seq) ? (int) $row->seq : null,
                'payload' => $payload,
            ];
        }, $rows));
    }

    /**
     * @param  array<int, stdClass>  $errors
     * @param  list<array{occurred_at: string, received_at: string, skew_ms: int|null, event: string, seq: int|null, payload: array<string, mixed>}>  $events
     * @param  list<string>  $except
     */
    private function printView(stdClass $view, array $errors, array $events, bool $raw, array $except, int $limit): void
    {
        $this->newLine();
        $this->info('View '.$view->view_id);

        $frames = $this->frames($view);
        $fields = [
            'video' => $view->video_id,
            'viewer ref' => $view->viewer_ref ?? null,
            'viewer id' => $view->viewer_id,
            'session id' => $view->session_id,
            'player' => trim(($view->player_name ?? '').' '.($view->player_version ?? '')),
            'device' => implode(' / ', array_filter([$view->browser ?? null, $view->os ?? null, $view->device_type ?? null, $view->connection_type ?? null])),
            'live' => $view->is_live === null ? null : ((bool) $view->is_live ? 'yes' : 'no'),
            'started' => $view->started_at,
            'first frame' => $view->first_frame_at,
            'ended' => $view->ended_at === null ? null : $view->ended_at.' ('.($view->exit_type ?? 'no exit type').')',
            'last event' => $view->last_event_at,
            'startup' => $this->ms($view->startup_ms),
            'watch / play' => $this->ms($view->watch_ms).' / '.$this->ms($view->play_ms),
            'rebuffers' => $view->rebuffer_count === null ? null : $view->rebuffer_count.' ('.$this->ms($view->rebuffer_ms).')',
            'reconnects' => isset($view->reconnect_count) ? $view->reconnect_count.' ('.$this->ms($view->reconnect_ms ?? null).')' : null,
            'errors / warnings' => ($view->error_count ?? '0').' / '.($view->warning_count ?? '0'),
            'frames dropped' => $frames,
            'qoe score' => $view->qoe_score,
            'completion' => isset($view->completion_ratio) ? round((float) $view->completion_ratio * 100, 1).'%' : null,
            'rebuffer share' => isset($view->rebuffer_fraction) ? round((float) $view->rebuffer_fraction * 100, 2).'%' : null,
            'page' => $view->page_url ?? null,
            'server' => $view->server,
            'custom' => $view->custom,
        ];

        $this->table(['Field', 'Value'], array_map(
            fn (string $field, mixed $value): array => [$field, $value === null || $value === '' ? '-' : (string) $value],
            array_keys($fields),
            $fields,
        ));

        if ($errors !== []) {
            $this->line('Errors');
            $this->table(['Occurred (client)', 'Severity', 'Category', 'Code', 'HTTP', 'Source host', 'Message'], array_map(fn (stdClass $error): array => [
                (string) $error->occurred_at,
                ($error->severity ?? ((bool) $error->fatal ? 'fatal' : '-')).((bool) ($error->reconnecting ?? false) ? ', reconnecting' : ''),
                $error->category ?? $error->type ?? '-',
                $error->code ?? '-',
                $error->http_status ?? '-',
                $error->source_host ?? '-',
                mb_strimwidth((string) ($error->message ?? ''), 0, 80, '...'),
            ], $errors));
        }

        if (! $raw) {
            $this->line('No timeline: beacons.store_raw_events is off.');

            return;
        }

        if ($limit === 0) {
            return;
        }

        if ($except !== []) {
            $this->line('Not in the raw log (beacons.raw_events_except): '.implode(', ', $except).'.');
        }

        if ($events === []) {
            $this->line('No raw events for this view (pruned, or never stored).');

            return;
        }

        $skews = array_values(array_filter(array_column($events, 'skew_ms'), 'is_int'));

        if ($skews !== []) {
            sort($skews);
            $this->line('Receipt minus sentAt, median '.$this->signed($skews[intdiv(count($skews), 2)]).' over '.count($skews).' beacons: network time plus how far the viewer\'s clock is behind the server (negative: ahead).');
        }

        $this->line('Timeline'.(count($events) === $limit ? ' (first '.$limit.', raise --events for more)' : ''));
        $this->table(['Occurred (client)', 'Received (server)', 'Receipt - sentAt', 'Event', 'Seq', 'Detail'], array_map(fn (array $event): array => [
            $event['occurred_at'],
            $event['received_at'],
            $event['skew_ms'] === null ? '-' : $this->signed($event['skew_ms']),
            $event['event'],
            $event['seq'] ?? '-',
            $this->detail($event['payload']),
        ], $events));
    }

    private function frames(stdClass $view): ?string
    {
        $decoded = $view->decoded_frames_total ?? null;
        $dropped = $view->dropped_frames_total ?? null;

        if (! is_numeric($decoded) || ! is_numeric($dropped)) {
            return null;
        }

        $share = (float) $decoded > 0 ? ' ('.round((float) $dropped / (float) $decoded * 100, 2).'%)' : '';

        return $dropped.' of '.$decoded.$share;
    }

    /** @param  array<string, mixed>  $payload */
    private function detail(array $payload): string
    {
        $parts = [];

        foreach (self::DETAIL_KEYS as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value)) {
                $parts[] = $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return mb_strimwidth(implode(' ', $parts), 0, 100, '...');
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function decodeMaps(array $view): array
    {
        foreach (['custom', 'custom_stamps', 'server', 'server_stamps'] as $column) {
            if (isset($view[$column]) && is_string($view[$column])) {
                $view[$column] = json_decode($view[$column], true);
            }
        }

        return $view;
    }

    private function ms(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        return (float) $value >= 1000 ? round((float) $value / 1000, 1).'s' : ((int) $value).'ms';
    }

    private function signed(int $milliseconds): string
    {
        return ($milliseconds >= 0 ? '+' : '-').number_format(abs($milliseconds) / 1000, 3).'s';
    }

    /** A stored UTC datetime with milliseconds as epoch milliseconds. */
    private function milliseconds(string $datetime): int
    {
        return (int) CarbonImmutable::parse($datetime, 'UTC')->getTimestampMs();
    }

    /**
     * Null without --since, false when it cannot be read.
     */
    private function since(mixed $value): CarbonImmutable|false|null
    {
        if (! $this->filled($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d+)([mhd])$/', $value, $match) === 1) {
            $now = CarbonImmutable::instance(Date::now());

            return match ($match[2]) {
                'm' => $now->subMinutes((int) $match[1]),
                'h' => $now->subHours((int) $match[1]),
                default => $now->subDays((int) $match[1]),
            };
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return false;
        }
    }

    private function whole(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) ? $value : null);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
