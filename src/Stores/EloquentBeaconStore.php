<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Stores;

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Events\ViewEnded;
use Hei\ScarlettPlayer\Events\ViewStarted;
use Hei\ScarlettPlayer\Exceptions\IncompleteMediaMappingException;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconStoreException;
use Hei\ScarlettPlayer\Media\ConfigModelResolver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The default BeaconStore: scarlett_views (one merged row per viewId), plus
 * scarlett_beacon_events (raw, optional) and scarlett_view_errors.
 *
 * THE MERGE IS THREE STATEMENTS, NOT ONE UPSERT. One transaction per beacon runs:
 *
 * 1. insertOrIgnore of the whole view row built from this beacon. One affected row
 *    means this worker created the view: ViewStarted fires, exactly once even when
 *    two workers race on the first beacon, because the unique view_id lets only one
 *    insert through. A beacon that inserted is done; the row already holds it. On
 *    MySQL and Postgres every attempt draws an id from the auto-increment sequence
 *    whether it inserts or not, so scarlett_views.id has a gap per beacon after the
 *    first; the id is a surrogate and nothing reads it as a count.
 * 2. For a viewEnd that did not insert: UPDATE ... SET ended_at WHERE ended_at IS
 *    NULL. One affected row means the view just ended: ViewEnded fires, once.
 * 3. One conditional UPDATE applying the merge classes to the columns this beacon
 *    carries:
 *    - set-once: COALESCE(col, incoming)
 *    - true-wins (is_live only): a true sets it, a false only fills an empty
 *      column, nothing clears it. A live viewStart fires before hls.js has read
 *      the playlist and says false, and a live stream that
 *      turns into a replay in the same session must not flip it back
 *    - monotonic: the larger of the two (CASE, not GREATEST, whose NULL handling
 *      differs between SQLite, MySQL and Postgres)
 *    - latest-by-timestamp: written when the column is empty or the beacon is at
 *      least as new as the stamp that wrote the column (qoe_score_at and its
 *      siblings, one per column; the live latency summary shares one because the
 *      player sends it as a unit). metrics_at is the newest of those stamps.
 *    - fill-if-absent: a key absent from the payload gets no assignment at all, so
 *      it never touches its column (SQL is built per beacon; no NULL bindings)
 *    The stamps, custom_at and last_event_at are assigned last, because MySQL
 *    evaluates UPDATE assignments left to right with the values already updated,
 *    where Postgres and SQLite read the old row.
 *
 * Why not a single INSERT ... ON CONFLICT DO UPDATE: the transition events need to
 * know whether the row was inserted and whether ended_at was null before the write,
 * and no single statement reports both on all three engines (MySQL's upsert says
 * only "inserted or updated", SQLite says nothing). Each statement here is atomic on
 * its row, and concurrent workers produce the same row as serial ones delivering in
 * the same order. The monotonic, true-wins, latest-by-timestamp and custom classes
 * are order-independent. Custom dimensions merge key by key with a stamp per key
 * (custom_stamps), read under a row lock and written back in statement 3; custom_at
 * is only the newest of those stamps. The server context (server, server_stamps)
 * merges the same way in the same locked read, and only for a beacon that carries
 * one: a host without beacons.context never reads or writes those columns. Ties: two beacons in the same millisecond
 * tie-break on their event key for custom keys, while the per-column latest group
 * (qoe_score and siblings) resolves an exact tie by processing order. The set-once class is not, by the plan's definition: the first
 * beacon to arrive with a value keeps it, so of two viewEnds delivered out of order
 * the first to arrive sets ended_at and exit_type, and the second only fills what
 * the first lacked.
 *
 * The host model link (viewable_type, viewable_id) is written after the transaction
 * commits, in its own conditional update, so a slow or failing ResolvesMedia never
 * holds the row lock or aborts the beacon's transaction.
 *
 * Raw events and errors are insert-or-ignore on a unique event_key, so a redelivered
 * job adds no row and fires no PlaybackErrorReported. Counters are never incremented
 * from the event stream: they are the player's own running totals, merged monotonic.
 *
 * A host's own BeaconStore must honour the same idempotency, merge and
 * transition semantics; see Contracts\BeaconStore.
 */
class EloquentBeaconStore implements BeaconStore
{
    public const VIEWS = 'scarlett_views';

    public const EVENTS = 'scarlett_beacon_events';

    public const ERRORS = 'scarlett_view_errors';

    /** Context key => column, set once. The four ids are handled beside these. */
    public const SET_ONCE = [
        'videoTitle' => 'video_title',
        'playerVersion' => 'player_version',
        'playerName' => 'player_name',
        'browser' => 'browser',
        'os' => 'os',
        'deviceType' => 'device_type',
        'screenSize' => 'screen_size',
        'playerSize' => 'player_size',
        'connectionType' => 'connection_type',
        'exitType' => 'exit_type',
    ];

    /**
     * Context key => boolean column where true wins: any beacon saying true sets it,
     * false only fills an empty column, and nothing clears it: a live viewStart is sent
     * before the playlist is read.
     */
    public const TRUE_WINS = [
        'isLive' => 'is_live',
    ];

    /**
     * Event key => column, never decreasing. Two keys may feed one column
     * (rebufferEnd carries the running total as totalRebufferTime).
     */
    public const MONOTONIC = [
        'startupTime' => 'startup_ms',
        'watchTime' => 'watch_ms',
        'playTime' => 'play_ms',
        'rebufferDuration' => 'rebuffer_ms',
        'totalRebufferTime' => 'rebuffer_ms',
        'rebufferCount' => 'rebuffer_count',
        'seekCount' => 'seek_count',
        'pauseCount' => 'pause_count',
        'qualityChanges' => 'quality_changes',
        'errorCount' => 'error_count',
        'maxBitrate' => 'max_bitrate',
    ];

    /**
     * Event key => [column, the stamp that records which beacon wrote it]. Newest
     * stamp wins per column.
     */
    public const LATEST = [
        'qoeScore' => ['qoe_score', 'qoe_score_at'],
        'avgBitrate' => ['avg_bitrate', 'avg_bitrate_at'],
        'rebufferRatio' => ['rebuffer_ratio', 'rebuffer_ratio_at'],
        'completionRate' => ['completion_rate', 'completion_rate_at'],
        'currentTime' => ['current_position', 'current_position_at'],
        'liveLatencySamples' => ['live_latency_samples', 'live_latency_at'],
        'liveLatencyMean' => ['live_latency_mean', 'live_latency_at'],
        'liveLatencyP95' => ['live_latency_p95', 'live_latency_at'],
        'liveLatencyMax' => ['live_latency_max', 'live_latency_at'],
        'lowLatency' => ['low_latency', 'live_latency_at'],
    ];

    /** Columns holding whole numbers; the player sends milliseconds and counts. */
    private const INTEGER_COLUMNS = [
        'startup_ms', 'watch_ms', 'play_ms', 'rebuffer_ms', 'rebuffer_count', 'seek_count',
        'pause_count', 'quality_changes', 'error_count', 'max_bitrate', 'avg_bitrate',
        'live_latency_samples',
    ];

    /** Whether scarlett_views has the ip_address column, checked once per store. */
    private ?bool $ipColumn = null;

    /** Whether the published raw events table has seq, checked once per store. */
    private ?bool $seqColumn = null;

    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly Container $container,
    ) {}

    public function record(BeaconPayload $payload): void
    {
        $connection = $this->connection();

        // Inspect before the transaction: SQLite must still WRITE first inside it
        // to acquire its lock without a read-to-write upgrade. Published older
        // migrations lack seq; keep ingesting until the host adds the column.
        if ($this->config->get('scarlett-player.beacons.store_raw_events') && ! $this->rawEventExcluded($payload->event)) {
            $this->seqColumn ??= $connection->getSchemaBuilder()->hasColumn(self::EVENTS, 'seq');
        }

        /** @var list<object> $fired */
        $fired = $connection->transaction(fn (): array => $this->write($connection, $payload), 3);

        if (array_filter($fired, fn (object $event): bool => $event instanceof ViewStarted) !== []) {
            $this->attachViewable($connection, $payload);
        }

        foreach ($fired as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * The event_key that makes a raw event or an error row unique:
     * sha1(viewId . event . timestamp . sha1(payload)), the payload being the beacon as
     * received (BeaconPayload::$bodyHash), so neither a pipeline redaction nor the
     * server context moves the key of a redelivered beacon.
     */
    public static function eventKey(BeaconPayload $payload): string
    {
        return sha1($payload->viewId.$payload->event.$payload->timestamp.$payload->bodyHash);
    }

    /**
     * Every write for one beacon. Returns the transition events to fire once the
     * transaction has committed, so a rolled-back beacon announces nothing.
     *
     * @return list<object>
     */
    private function write(Connection $connection, BeaconPayload $payload): array
    {
        $fired = [];
        $key = self::eventKey($payload);
        $now = $this->serverNow();

        // Lock first on MySQL and Postgres: an INSERT IGNORE that hits the existing row
        // takes a shared lock on InnoDB, and two workers holding S on one view both then
        // wanting X (the merge) is the classic S-to-X deadlock. Taking X up front, before
        // any other table, gives every worker the same lock order. Not on SQLite: its
        // transaction serialises only because the first statement is a write (BEGIN
        // DEFERRED takes the write lock then); a read first would share and deadlock on
        // the upgrade. Do not add a read before the first write there.
        $exists = $connection->getDriverName() !== 'sqlite'
            && $connection->table(self::VIEWS)->where('view_id', $payload->viewId)->lockForUpdate()->first(['id']) !== null;

        if ($this->config->get('scarlett-player.beacons.store_raw_events') && ! $this->rawEventExcluded($payload->event)) {
            $row = [
                'view_id' => $payload->viewId,
                'event' => $payload->event,
                'event_key' => $key,
                'occurred_at' => self::clientTime($payload->timestamp),
                'payload' => json_encode($payload->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'received_at' => $now,
            ];

            $seq = $payload->get('beaconSeq');

            if ($this->seqColumn === true && (is_int($seq) || is_float($seq))) {
                $seq = $this->rawSequence($seq);

                if ($seq !== null) {
                    $row['seq'] = $seq;
                }
            }

            $connection->table(self::EVENTS)->insertOrIgnore($row);
        }

        if ($payload->event === 'error' && $this->insertError($connection, $payload, $key, $now)) {
            $fired[] = new PlaybackErrorReported($payload->viewId, $payload);
        }

        if (! $exists && $connection->table(self::VIEWS)->insertOrIgnore($this->insertRow($connection, $payload, $now)) === 1) {
            $fired[] = new ViewStarted($payload->viewId, $payload);

            if ($payload->event === 'viewEnd') {
                $fired[] = new ViewEnded($payload->viewId, $payload);
            }

            return $fired;
        }

        if ($payload->event === 'viewEnd') {
            $ended = $connection->table(self::VIEWS)
                ->where('view_id', $payload->viewId)
                ->whereNull('ended_at')
                ->update(['ended_at' => self::clientTime($payload->timestamp)]);

            if ($ended === 1) {
                $fired[] = new ViewEnded($payload->viewId, $payload);
            }
        }

        $this->merge($connection, $payload, $now);

        return $fired;
    }

    /**
     * The view row as this beacon alone would build it.
     *
     * @return array<string, mixed>
     */
    private function insertRow(Connection $connection, BeaconPayload $payload, string $now): array
    {
        $at = self::clientTime($payload->timestamp);

        $row = [
            'view_id' => $payload->viewId,
            'session_id' => $payload->sessionId,
            'viewer_id' => $payload->viewerId,
            'video_id' => $payload->videoId,
            'last_event_at' => $at,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($this->eventStamps($payload) as $column => $value) {
            $row[$column] = $value;
        }

        foreach ($this->setOnce($connection, $payload) as $column => $value) {
            $row[$column] = $value;
        }

        foreach ($this->trueWins($payload) as $column => $value) {
            $row[$column] = $value;
        }

        foreach ($this->monotonic($payload) as $column => $value) {
            $row[$column] = $value;
        }

        $latest = $this->latest($payload);

        foreach ($latest as $column => [$value, $stamp]) {
            $row[$column] = $value;
            $row[$stamp] = $at;
        }

        if ($latest !== []) {
            $row['metrics_at'] = $at;
        }

        if ($payload->custom !== []) {
            $row['custom'] = $this->json($payload->custom);
            $row['custom_stamps'] = $this->json(array_fill_keys(array_keys($payload->custom), self::customStamp($payload)));
            $row['custom_at'] = $at;
        }

        // Only when the beacon carries server keys: a host without beacons.context never
        // touches the columns, so a table migrated by 0.1.0, which lacks them, still works.
        if ($payload->server !== []) {
            $row['server'] = $this->json($payload->server);
            $row['server_stamps'] = $this->json(array_fill_keys(array_keys($payload->server), self::customStamp($payload)));
        }

        return $row;
    }

    /**
     * Statement 3: the conditional merge into an existing row.
     */
    private function merge(Connection $connection, BeaconPayload $payload, string $now): void
    {
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable(self::VIEWS);
        $col = fn (string $column): string => $table.'.'.$grammar->wrap($column);
        $at = self::clientTime($payload->timestamp);

        $sets = [];
        $bindings = [];

        foreach ([...$this->eventStamps($payload), ...$this->setOnce($connection, $payload)] as $column => $value) {
            $sets[] = $grammar->wrap($column).' = COALESCE('.$col($column).', ?)';
            $bindings[] = $value;
        }

        // True wins: a true is written outright (idempotent), a false only fills.
        foreach ($this->trueWins($payload) as $column => $value) {
            $sets[] = $value
                ? $grammar->wrap($column).' = ?'
                : $grammar->wrap($column).' = COALESCE('.$col($column).', ?)';
            $bindings[] = $value;
        }

        foreach ($this->monotonic($payload) as $column => $value) {
            $sets[] = $grammar->wrap($column).' = CASE WHEN '.$col($column).' IS NULL OR '.$col($column).' < ? THEN ? ELSE '.$col($column).' END';
            array_push($bindings, $value, $value);
        }

        $latest = $this->latest($payload);

        foreach ($latest as $column => [$value, $stamp]) {
            $sets[] = $grammar->wrap($column).' = CASE WHEN '.$col($column).' IS NULL OR '.$col($stamp).' IS NULL OR ? >= '.$col($stamp).' THEN ? ELSE '.$col($column).' END';
            array_push($bindings, $at, $value);
        }

        foreach ($this->mergeMaps($connection, $payload) as $column => [$values, $stamps]) {
            $sets[] = $grammar->wrap($column).' = ?';
            $sets[] = $grammar->wrap($column.'_stamps').' = ?';
            array_push($bindings, $this->json($values), $this->json($stamps));
        }

        // Timestamps last: see the class docblock on MySQL's assignment order.
        $stamps = array_values(array_unique(array_column($latest, 1)));

        foreach ([...$stamps, ...($latest !== [] ? ['metrics_at'] : [])] as $stamp) {
            $sets[] = $this->laterOf($grammar->wrap($stamp), $col($stamp));
            array_push($bindings, $at, $at);
        }

        if ($payload->custom !== []) {
            $sets[] = $this->laterOf($grammar->wrap('custom_at'), $col('custom_at'));
            array_push($bindings, $at, $at);
        }

        $sets[] = $this->laterOf($grammar->wrap('last_event_at'), $col('last_event_at'));
        array_push($bindings, $at, $at);

        $sets[] = $grammar->wrap('updated_at').' = ?';
        $bindings[] = $now;

        $bindings[] = $payload->viewId;

        $connection->update(
            'UPDATE '.$table.' SET '.implode(', ', $sets).' WHERE '.$col('view_id').' = ?',
            $bindings,
        );
    }

    private function laterOf(string $target, string $column): string
    {
        return $target.' = CASE WHEN '.$column.' IS NULL OR '.$column.' < ? THEN ? ELSE '.$column.' END';
    }

    /**
     * The JSON maps this beacon writes (custom, the browser's; server, the host's),
     * each merged key by key, the last writer by timestamp winning each key: an
     * incoming key is written only when this beacon is at least as new as the beacon
     * that last wrote that key ({map}_stamps, customStamp() per key). The row is read
     * with a lock inside the beacon's transaction, both maps in one SELECT, so two
     * workers merging into one view serialise here; the result goes back in
     * statement 3. Done in PHP rather than SQL because no JSON function does a
     * per-key conditional merge on all three engines, and PHP replaces a value
     * whole, the same on every engine.
     *
     * A map the beacon does not carry is neither read nor written, so the server
     * columns are touched only by a beacon with server context.
     *
     * @return array<string, array{0: array<array-key, mixed>, 1: array<array-key, string>}>
     */
    private function mergeMaps(Connection $connection, BeaconPayload $payload): array
    {
        $incoming = array_filter(['custom' => $payload->custom, 'server' => $payload->server]);

        if ($incoming === []) {
            return [];
        }

        $columns = [];

        foreach (array_keys($incoming) as $column) {
            array_push($columns, $column, $column.'_stamps');
        }

        $row = $connection->table(self::VIEWS)
            ->where('view_id', $payload->viewId)
            ->lockForUpdate()
            ->first($columns);

        $stamp = self::customStamp($payload);
        $merged = [];

        foreach ($incoming as $column => $map) {
            $values = $this->decode($row->{$column} ?? null);
            $stamps = array_map('strval', $this->decode($row->{$column.'_stamps'} ?? null));

            foreach ($map as $key => $value) {
                if (! isset($stamps[$key]) || strcmp($stamp, $stamps[$key]) >= 0) {
                    $values[$key] = $value;
                    $stamps[$key] = $stamp;
                }
            }

            $merged[$column] = [$values, $stamps];
        }

        return $merged;
    }

    /**
     * A map key's stamp, for custom and server alike: the beacon's timestamp,
     * zero-padded, then its event key, so string order is timestamp order and two
     * different beacons in the same millisecond tie-break on the event key (the larger
     * wins) instead of on processing order.
     */
    public static function customStamp(BeaconPayload $payload): string
    {
        return sprintf('%015d:%s', $payload->timestamp, self::eventKey($payload));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : [];
    }

    private function insertError(Connection $connection, BeaconPayload $payload, string $key, string $now): bool
    {
        $code = $payload->get('errorCode');

        return $connection->table(self::ERRORS)->insertOrIgnore([
            'view_id' => $payload->viewId,
            'video_id' => $payload->videoId,
            'event_key' => $key,
            'type' => $this->nullableString($payload->get('errorType'), 255),
            'message' => $this->nullableString($payload->get('errorMessage'), 65535),
            'code' => $this->nullableString($code, 255),
            'fatal' => $payload->get('fatal') === true,
            'occurred_at' => self::clientTime($payload->timestamp),
            'received_at' => $now,
        ]) === 1;
    }

    /**
     * Set-once stamps an event carries by its name.
     *
     * @return array<string, string>
     */
    private function eventStamps(BeaconPayload $payload): array
    {
        $at = self::clientTime($payload->timestamp);

        return match ($payload->event) {
            'viewStart' => ['started_at' => $at],
            'videoStart' => ['first_frame_at' => $at],
            // ended_at goes through statement 2 when the row exists; this stamp only
            // reaches the insert, and COALESCE keeps the merge harmless if it runs.
            'viewEnd' => ['ended_at' => $at],
            default => [],
        };
    }

    /**
     * @return array<string, string|bool>
     */
    private function setOnce(Connection $connection, BeaconPayload $payload): array
    {
        $columns = [];

        foreach (self::SET_ONCE as $key => $column) {
            $value = $payload->get($key);

            if ($value !== null) {
                $columns[$column] = is_bool($value) ? $value : $this->string($value, 255);
            }
        }

        if ($payload->ip !== null && $this->storesIp($connection)) {
            $columns['ip_address'] = $payload->ip;
        }

        return $columns;
    }

    /**
     * The true-wins columns this beacon carries; a null isLive is absent already.
     *
     * @return array<string, bool>
     */
    private function trueWins(BeaconPayload $payload): array
    {
        $columns = [];

        foreach (self::TRUE_WINS as $key => $column) {
            $value = $payload->get($key);

            if (is_bool($value)) {
                $columns[$column] = $value;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, int>
     */
    private function monotonic(BeaconPayload $payload): array
    {
        $columns = [];

        foreach (self::MONOTONIC as $key => $column) {
            $value = $payload->get($key);

            if (is_int($value) || is_float($value)) {
                $columns[$column] = max($columns[$column] ?? 0, $this->whole($value));
            }
        }

        return $columns;
    }

    /**
     * Column => [value, stamp column] for the latest-by-timestamp keys present.
     *
     * @return array<string, array{int|float|bool, string}>
     */
    private function latest(BeaconPayload $payload): array
    {
        $columns = [];

        foreach (self::LATEST as $key => [$column, $stamp]) {
            $value = $payload->get($key);

            if (is_bool($value)) {
                $columns[$column] = [$value, $stamp];
            } elseif (is_int($value) || is_float($value)) {
                $columns[$column] = [in_array($column, self::INTEGER_COLUMNS, true) ? $this->whole($value) : (float) $value, $stamp];
            }
        }

        return $columns;
    }

    /**
     * Link a newly stored view to the host model its videoId resolves to, after the
     * beacon's transaction has committed. Best effort: a view is worth keeping without
     * the link, so an unknown id or a failing resolver leaves it null rather than
     * failing the beacon. A host that uses beacons without a media mapping (the
     * default resolver with media.model unset) is not asked at all, and an incomplete
     * mapping is expected there, so it is not reported; any other failure is.
     */
    private function attachViewable(Connection $connection, BeaconPayload $payload): void
    {
        $resolver = $this->resolver();

        if ($resolver instanceof ConfigModelResolver && $this->config->get('scarlett-player.media.model') === null) {
            return;
        }

        try {
            $model = $resolver->resolve($payload->videoId)?->model;
        } catch (IncompleteMediaMappingException) {
            return;
        } catch (Throwable $e) {
            if ($this->container->bound(ExceptionHandler::class)) {
                $this->container->make(ExceptionHandler::class)->report($e);
            }

            return;
        }

        if ($model === null) {
            return;
        }

        $connection->table(self::VIEWS)
            ->where('view_id', $payload->viewId)
            ->whereNull('viewable_type')
            ->update([
                'viewable_type' => $model->getMorphClass(),
                'viewable_id' => (string) $model->getKey(),
            ]);
    }

    /**
     * The bound resolver, typed as the contract: a host may bind any ResolvesMedia.
     */
    private function resolver(): ResolvesMedia
    {
        return $this->container->make(ResolvesMedia::class);
    }

    /**
     * beacons.store_ip is on AND the column exists. The column is created only when
     * store_ip was on at migration time, so a host that turns it on later would
     * otherwise fail every beacon; the address is skipped instead and scarlett:doctor
     * reports the missing column (BeaconIpColumnCheck).
     */
    private function storesIp(Connection $connection): bool
    {
        if (! $this->config->get('scarlett-player.beacons.store_ip')) {
            return false;
        }

        return $this->ipColumn ??= $connection->getSchemaBuilder()->hasColumn(self::VIEWS, 'ip_address');
    }

    /**
     * The event is named in beacons.raw_events_except. Only string entries count; a
     * non-list setting or any other entry is ignored rather than failing the beacon.
     */
    private function rawEventExcluded(string $event): bool
    {
        $except = $this->config->get('scarlett-player.beacons.raw_events_except', []);

        return is_array($except) && in_array($event, array_filter($except, 'is_string'), true);
    }

    private function connection(): Connection
    {
        $connection = $this->database->connection();

        if (! $connection instanceof Connection) {
            throw InvalidBeaconStoreException::unsupportedConnection($connection::class);
        }

        return $connection;
    }

    private function serverNow(): string
    {
        return Date::now()->utc()->format('Y-m-d H:i:s.v');
    }

    /**
     * The player's epoch-millisecond timestamp as a UTC datetime with milliseconds.
     */
    public static function clientTime(int $milliseconds): string
    {
        $seconds = intdiv($milliseconds, 1000);

        return gmdate('Y-m-d H:i:s', $seconds).'.'.str_pad((string) ($milliseconds % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Use the portable SQL integer range: PostgreSQL has a signed integer even
     * for unsignedInteger migrations. Omit unusable order keys without changing
     * the raw payload or dropping the beacon's view metrics. Bound before casting
     * so a large finite value cannot wrap into a valid-looking sequence number.
     */
    private function rawSequence(int|float $value): ?int
    {
        if (! is_finite($value)) {
            return null;
        }

        $rounded = max(0, round($value, 0, PHP_ROUND_HALF_UP));

        return $rounded > 2147483647 ? null : (int) $rounded;
    }

    private function whole(int|float $value): int
    {
        return max(0, (int) round($value));
    }

    private function string(int|float|string|bool $value, int $max): string
    {
        return mb_substr(is_bool($value) ? ($value ? '1' : '0') : (string) $value, 0, $max);
    }

    private function nullableString(int|float|string|bool|null $value, int $max): ?string
    {
        return $value === null ? null : $this->string($value, $max);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        // As an object at the top level: a map whose keys are all numeric strings ("0",
        // "1") would otherwise encode as a JSON list. Not JSON_FORCE_OBJECT, which would
        // turn nested lists into objects too.
        return (string) json_encode((object) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
