<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Hei\ScarlettPlayer\Beacons\GaugeNormalizer;
use Hei\ScarlettPlayer\Beacons\GaugeReading;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use stdClass;

/**
 * Fills the canonical gauge columns (completion_ratio, rebuffer_fraction) from
 * the legacy gauge columns (completion_rate, rebuffer_ratio), preserving the
 * originals. Dry run by default; --apply writes.
 *
 * A historical gauge's scale is never guessed from its magnitude. For each
 * measurement the command seeks the view's retained post-pipeline raw evidence
 * matching the gauge's own latest timestamp and value; the evidence's own
 * marker and producer identity decide the scale through GaugeNormalizer, the
 * same inputs ingest reads. The raw log keeps an explicit null marker, so it
 * stays invalid here as at ingest. It also overlays the server context on the
 * browser's keys, while ingest reads the marker and producer only from the
 * browser: when the view's server map holds any of those keys, the evidence
 * cannot show what ingest would have seen, and the measurement is ambiguous. Tied
 * conflicting evidence is ambiguous. Missing raw retention leaves the value
 * unavailable. Ambiguous or missing-evidence measurements with a trustworthy
 * legacy stamp get a canonical null plus that stamp once, marking the
 * measurement processed so reruns resume instead of refilling it. Rows whose
 * legacy stamp is missing are left untouched (insufficient provenance).
 *
 * Writes are compare-and-swap against the observed legacy value/stamp and the
 * canonical stamp, so live ingest that changes the measurement between the read
 * and the write wins, and a rerun never divides twice or overwrites a fresher
 * measurement. Live rows get an unavailable completion, matching ingest.
 */
class BackfillGaugesCommand extends Command
{
    /** Gauge name => legacy/canonical columns and the wire key in raw payloads. */
    private const GAUGES = [
        'completion' => [
            'legacy' => 'completion_rate',
            'legacy_stamp' => 'completion_rate_at',
            'canonical' => 'completion_ratio',
            'canonical_stamp' => 'completion_ratio_at',
            'wire' => 'completionRate',
        ],
        'rebuffer' => [
            'legacy' => 'rebuffer_ratio',
            'legacy_stamp' => 'rebuffer_ratio_at',
            'canonical' => 'rebuffer_fraction',
            'canonical_stamp' => 'rebuffer_fraction_at',
            'wire' => 'rebufferRatio',
        ],
    ];

    /** The raw evidence keys that decide a scale; a server-owned one is not the browser's. */
    private const SCALE_KEYS = ['gaugeScale', 'playerName', 'playerVersion'];

    private const CANONICAL_COLUMNS = [
        'completion_ratio', 'completion_ratio_at', 'rebuffer_fraction', 'rebuffer_fraction_at',
    ];

    protected $signature = 'scarlett:views:backfill-gauges
        {--apply : Write the planned changes; without it the command is a dry run}
        {--batch=250 : View rows per keyset page}';

    protected $description = 'Fill the canonical gauge ratio columns from the legacy gauge columns and retained raw evidence (dry run unless --apply)';

    public function handle(ConnectionResolverInterface $database): int
    {
        $batch = (string) $this->option('batch');

        if (! ctype_digit($batch) || (int) $batch < 1) {
            $this->error('--batch takes a whole number of rows.');

            return self::FAILURE;
        }

        $connection = $database->connection();

        if (! $connection instanceof Connection) {
            $this->error('The default database connection does not support schema inspection.');

            return self::FAILURE;
        }

        if (! $connection->getSchemaBuilder()->hasColumns(EloquentBeaconStore::VIEWS, self::CANONICAL_COLUMNS)) {
            $this->error('The gauge columns are missing. Publish --tag=scarlett-migrations-gauges and migrate first.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $summary = array_fill_keys([
            'proven percent', 'proven ratio', 'clamped', 'invalid marker', 'ambiguous',
            'missing raw evidence', 'live unavailable', 'already processed',
            'changed concurrently', 'insufficient provenance', 'no measurement', 'written',
        ], 0);

        $cursor = '';

        do {
            $rows = $connection->table(EloquentBeaconStore::VIEWS)
                ->where('view_id', '>', $cursor)
                ->orderBy('view_id')
                ->limit((int) $batch)
                ->get();

            foreach ($rows as $row) {
                foreach (self::GAUGES as $name => $spec) {
                    $this->backfillGauge($connection, $row, $name, $spec, $apply, $summary);
                }

                $cursor = (string) $row->view_id;
            }
        } while ($rows->count() === (int) $batch);

        if ($apply) {
            $this->info('Applied. The legacy gauge columns were not changed.');
        } else {
            $this->info('dry run: nothing was written; rerun with --apply to write this plan.');
        }

        $this->table(
            ['Bucket', 'Measurements'],
            collect($summary)->map(fn (int $count, string $bucket): array => [$bucket, (string) $count])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Plan (and with --apply, write) one gauge of one row.
     *
     * @param  array<string, string>  $spec
     * @param  array<string, int>  $summary
     */
    private function backfillGauge(Connection $connection, stdClass $row, string $name, array $spec, bool $apply, array &$summary): void
    {
        if ($row->{$spec['canonical_stamp']} !== null) {
            $summary['already processed']++;

            return;
        }

        $legacyValue = $row->{$spec['legacy']};
        $legacyStamp = $row->{$spec['legacy_stamp']};

        if ($legacyValue === null) {
            $summary['no measurement']++;

            return;
        }

        if ($legacyStamp === null) {
            // Out-of-band write: without its measurement stamp there is no
            // provenance, and server-now must never substitute one.
            $summary['insufficient provenance']++;

            return;
        }

        $legacyStamp = (string) $legacyStamp;

        if ($name === 'completion' && (bool) $row->is_live) {
            $this->write($connection, $row, $spec, null, $legacyValue, $legacyStamp, $apply, $summary, 'live unavailable');

            return;
        }

        [$value, $bucket] = $this->decide($connection, $row, $spec, $legacyValue, $legacyStamp);
        $this->write($connection, $row, $spec, $value, $legacyValue, $legacyStamp, $apply, $summary, $bucket);
    }

    /**
     * The canonical value for one legacy measurement and its report bucket,
     * from the retained raw evidence matching the measurement's stamp and value.
     *
     * @param  array<string, string>  $spec
     * @return array{0: float|null, 1: string}
     */
    private function decide(Connection $connection, stdClass $row, array $spec, mixed $legacyValue, string $legacyStamp): array
    {
        $matching = [];

        foreach ($connection->table(EloquentBeaconStore::EVENTS)
            ->where('view_id', $row->view_id)
            ->where('occurred_at', $legacyStamp)
            ->pluck('payload') as $json) {
            $payload = is_string($json) ? json_decode($json, true) : null;

            if (! is_array($payload) || ! array_key_exists($spec['wire'], $payload)) {
                continue;
            }

            $candidate = $payload[$spec['wire']];

            if ((is_int($candidate) || is_float($candidate)) && (float) $candidate === (float) $legacyValue) {
                $matching[] = $payload;
            }
        }

        if ($matching === []) {
            return [null, 'missing raw evidence'];
        }

        if ($this->serverOwnsScaleKey($row)) {
            return [null, 'ambiguous'];
        }

        $readings = [];

        foreach ($matching as $payload) {
            $readings[] = GaugeNormalizer::normalize(
                $legacyValue,
                array_key_exists('gaugeScale', $payload),
                $payload['gaugeScale'] ?? null,
                $payload['playerName'] ?? null,
                $payload['playerVersion'] ?? null,
            );
        }

        $available = array_values(array_filter($readings, fn (GaugeReading $reading): bool => $reading->available));

        if ($available === []) {
            $reasons = array_unique(array_map(fn (GaugeReading $reading): string => $reading->reason, $readings));

            return [null, $reasons === [GaugeNormalizer::REASON_INVALID_MARKER] ? 'invalid marker' : 'ambiguous'];
        }

        $values = array_unique(array_map(fn (GaugeReading $reading): float => $reading->value ?? 0.0, $available));

        if (count($available) !== count($readings) || count($values) !== 1) {
            // The tied evidence disagrees on the scale; magnitude cannot decide.
            return [null, 'ambiguous'];
        }

        $clamped = array_filter($available, fn (GaugeReading $reading): bool => str_ends_with($reading->reason, GaugeNormalizer::REASON_CLAMPED));

        if ($clamped !== []) {
            return [$values[0], 'clamped'];
        }

        return [$values[0], str_contains($available[0]->reason, 'percent') ? 'proven percent' : 'proven ratio'];
    }

    /**
     * Whether the view's server context set a key the scale is decided from.
     * The raw log replaces the browser's value with it, so the evidence no
     * longer shows the browser's. Tables without the server column never had
     * a server context.
     */
    private function serverOwnsScaleKey(stdClass $row): bool
    {
        $server = property_exists($row, 'server') && is_string($row->server) ? json_decode($row->server, true) : null;

        return is_array($server) && array_intersect(self::SCALE_KEYS, array_keys($server)) !== [];
    }

    /**
     * The compare-and-swap write (or its dry-run count): the canonical value and
     * the source measurement stamp together, guarded by the observed legacy
     * value/stamp and an unprocessed canonical stamp, so concurrent ingest wins.
     *
     * @param  array<string, string>  $spec
     * @param  array<string, int>  $summary
     */
    private function write(Connection $connection, stdClass $row, array $spec, ?float $value, mixed $legacyValue, string $legacyStamp, bool $apply, array &$summary, string $bucket): void
    {
        $summary[$bucket]++;

        if (! $apply) {
            return;
        }

        $updated = $connection->table(EloquentBeaconStore::VIEWS)
            ->where('view_id', $row->view_id)
            ->where($spec['legacy'], $legacyValue)
            ->where($spec['legacy_stamp'], $legacyStamp)
            ->whereNull($spec['canonical_stamp'])
            ->update([
                $spec['canonical'] => $value,
                $spec['canonical_stamp'] => $legacyStamp,
            ]);

        if ($updated === 1) {
            $summary['written']++;
        } else {
            $summary['changed concurrently']++;
        }
    }
}
