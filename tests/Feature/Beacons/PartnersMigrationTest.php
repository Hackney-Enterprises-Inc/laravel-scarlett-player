<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Exceptions\PartnerRefIndexException;
use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerGate;
use Hei\ScarlettPlayer\Partners\PartnerOrigin;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Migrations 11 (create scarlett_partners) and 12 (partner_ref on scarlett_views):
 * idempotent for an absent or ordinary index, a unique index on exactly partner_ref
 * refused with a named exception and no change (never repaired or dropped), a rollback
 * that drops every index on exactly partner_ref, and a rollback that only the last
 * recorded copy of each is allowed to act on.
 */

const PARTNERS_CREATE = __DIR__.'/../../../database/migrations/0001_01_01_000011_create_scarlett_partners_table.php';

const PARTNERS_REF = __DIR__.'/../../../database/migrations/0001_01_01_000012_add_partner_ref_to_scarlett_views_table.php';

beforeEach(function (): void {
    partnersMigrationReset();
});

afterEach(function (): void {
    partnersMigrationReset();
});

function partnersMigrationReset(): void
{
    foreach (['scarlett_partners', 'scarlett_clips', 'scarlett_view_errors', 'scarlett_beacon_events', 'scarlett_views'] as $table) {
        Schema::dropIfExists($table);
    }
    if (Schema::hasTable('migrations')) {
        DB::table('migrations')->where('migration', 'like', '%scarlett%')->delete();
    }
}

function partnersMigrationViews(): void
{
    foreach (glob(__DIR__.'/../../../database/migrations/0001_01_01_00000[1]_*.php') as $create) {
        (require $create)->up();
    }
}

/** @return array<string, mixed> */
function partnersMigrationRow(string $ref = 'acme', ?string $keyId = null): array
{
    return [
        'ref' => $ref,
        'key_id' => $keyId ?? str_repeat('a', 32),
        'name' => 'Acme',
        'origins' => json_encode(['https://acme.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** Record a migration as run, the way two published copies of one upgrade would be. */
function partnersMigrationRecord(string ...$names): void
{
    $repository = app('migration.repository');

    if (! $repository->repositoryExists()) {
        $repository->createRepository();
    }

    foreach ($names as $name) {
        DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
    }
}

function partnersMigrationUniques(): array
{
    return [
        'ref' => Schema::hasIndex('scarlett_partners', ['ref'], 'unique'),
        'key_id' => Schema::hasIndex('scarlett_partners', ['key_id'], 'unique'),
    ];
}

it('creates the partners table and is a no-op on rerun', function (): void {
    (require PARTNERS_CREATE)->up();

    expect(Schema::hasTable('scarlett_partners'))->toBeTrue()
        ->and(Schema::hasColumns('scarlett_partners', ['id', 'ref', 'key_id', 'name', 'origins', 'expires_at', 'revoked_at', 'created_at', 'updated_at']))->toBeTrue()
        ->and(partnersMigrationUniques())->toBe(['ref' => true, 'key_id' => true]);

    DB::table('scarlett_partners')->insert(partnersMigrationRow());
    (require PARTNERS_CREATE)->up();

    expect(DB::table('scarlett_partners')->count())->toBe(1)
        ->and(partnersMigrationUniques())->toBe(['ref' => true, 'key_id' => true]);
});

it('stores expires_at and revoked_at as dateTime, not timestamp', function (): void {
    (require PARTNERS_CREATE)->up();

    $types = collect(Schema::getColumns('scarlett_partners'))->keyBy('name');

    foreach (['expires_at', 'revoked_at'] as $column) {
        expect(strtolower((string) $types[$column]['type_name']))->toContain('datetime')
            ->and($types[$column]['nullable'])->toBeTrue();
    }
})->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL has no datetime type: dateTime is timestamp(0) there.');

it('rejects a duplicate ref and a duplicate key_id', function (): void {
    (require PARTNERS_CREATE)->up();
    DB::table('scarlett_partners')->insert(partnersMigrationRow('acme', str_repeat('a', 32)));

    expect(fn () => DB::table('scarlett_partners')->insert(partnersMigrationRow('acme', str_repeat('b', 32))))->toThrow(QueryException::class)
        ->and(fn () => DB::table('scarlett_partners')->insert(partnersMigrationRow('other', str_repeat('a', 32))))->toThrow(QueryException::class);
});

it('adds partner_ref and its index to scarlett_views and is a no-op on rerun', function (): void {
    partnersMigrationViews();

    (require PARTNERS_REF)->up();
    (require PARTNERS_REF)->up();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeTrue()
        ->and(Schema::hasIndex('scarlett_views', ['partner_ref']))->toBeTrue()
        ->and(Schema::hasIndex('scarlett_views', 'scarlett_views_partner_ref_index'))->toBeTrue();

    (require PARTNERS_REF)->down();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeFalse()
        ->and(Schema::hasIndex('scarlett_views', 'scarlett_views_partner_ref_index'))->toBeFalse();
});

it('throws the named order exception when scarlett_views does not exist', function (): void {
    expect(fn () => (require PARTNERS_REF)->up())
        ->toThrow(UpgradeMigrationOrderException::class, 'scarlett_views');
});

it('leaves a differently named index on partner_ref alone and rolls back on every engine', function (): void {
    partnersMigrationViews();
    Schema::table('scarlett_views', function ($table): void {
        $table->string('partner_ref', 64)->nullable();
        $table->index('partner_ref', 'host_partner_lookup');
    });

    (require PARTNERS_REF)->up();

    expect(Schema::hasIndex('scarlett_views', 'host_partner_lookup'))->toBeTrue()
        ->and(Schema::hasIndex('scarlett_views', 'scarlett_views_partner_ref_index'))->toBeFalse();

    // The index covering exactly partner_ref is dropped by its columns, whatever its name.
    (require PARTNERS_REF)->down();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeFalse()
        ->and(Schema::hasIndex('scarlett_views', 'host_partner_lookup'))->toBeFalse();
});

it('is idempotent over an ordinary index on partner_ref and rolls it back', function (): void {
    partnersMigrationViews();
    Schema::table('scarlett_views', function ($table): void {
        $table->string('partner_ref', 64)->nullable();
        $table->index('partner_ref', 'host_partner_lookup');
    });

    (require PARTNERS_REF)->up();

    expect(partnersMigrationRefIndexNames())->toBe(['host_partner_lookup']);

    (require PARTNERS_REF)->up();

    expect(partnersMigrationRefIndexNames())->toBe(['host_partner_lookup']);

    (require PARTNERS_REF)->down();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeFalse()
        ->and(Schema::hasIndex('scarlett_views', ['partner_ref']))->toBeFalse();
});

/** @return list<string> */
function partnersMigrationRefIndexNames(): array
{
    $names = [];

    foreach (Schema::getIndexes('scarlett_views') as $index) {
        if (! $index['primary'] && array_map('strtolower', $index['columns']) === ['partner_ref']) {
            $names[] = $index['name'];
        }
    }

    sort($names);

    return $names;
}

/** A host's unique index on partner_ref, of the given kind, on a table that already has the column. */
function partnersMigrationHostUnique(string $kind): void
{
    Schema::table('scarlett_views', function ($table) use ($kind): void {
        $table->string('partner_ref', 64)->nullable();

        if ($kind === 'constraint') {
            // A UNIQUE constraint: on PostgreSQL its backing index cannot be dropped alone.
            $table->unique('partner_ref', 'host_partner_unique');
        }
    });

    if ($kind === 'standalone') {
        DB::statement('create unique index host_partner_standalone on scarlett_views (partner_ref)');
    }

    if ($kind === 'package-named') {
        DB::statement('create unique index scarlett_views_partner_ref_index on scarlett_views (partner_ref)');
    }
}

/** @return array{columns: list<string>, indexes: list<string>} */
function partnersMigrationSnapshot(): array
{
    $columns = Schema::getColumnListing('scarlett_views');
    $indexes = array_map(
        fn (array $index): string => $index['name'].'('.implode(',', $index['columns']).')'.($index['unique'] ? ' unique' : ''),
        Schema::getIndexes('scarlett_views'),
    );

    sort($columns);
    sort($indexes);

    return ['columns' => $columns, 'indexes' => $indexes];
}

it('refuses a unique index on partner_ref with the named exception and changes nothing', function (string $kind): void {
    partnersMigrationViews();
    partnersMigrationHostUnique($kind);
    $before = partnersMigrationSnapshot();
    $name = ['standalone' => 'host_partner_standalone', 'constraint' => 'host_partner_unique', 'package-named' => 'scarlett_views_partner_ref_index'][$kind];

    $thrown = null;

    try {
        (require PARTNERS_REF)->up();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(PartnerRefIndexException::class)
        ->and($thrown->getMessage())->toContain('partner_ref', 'one partner has many views', $name, 'drop')
        ->and(partnersMigrationSnapshot())->toBe($before);

    // A retry refuses again, still with no change.
    expect(fn () => (require PARTNERS_REF)->up())->toThrow(PartnerRefIndexException::class)
        ->and(partnersMigrationSnapshot())->toBe($before);
})->with(['standalone', 'constraint', 'package-named']);

it('leaves the host unique index as the only thing refusing a second view for one partner', function (): void {
    partnersMigrationViews();
    partnersMigrationHostUnique('standalone');

    expect(fn () => (require PARTNERS_REF)->up())->toThrow(PartnerRefIndexException::class);

    $view = fn (string $id): array => ['view_id' => $id, 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'partner_ref' => 'acme', 'created_at' => now(), 'updated_at' => now()];

    DB::table('scarlett_views')->insert($view('one'));

    expect(fn () => DB::table('scarlett_views')->insert($view('two')))->toThrow(QueryException::class);

    // Dropping the host's index and rerunning is the documented fix.
    Schema::table('scarlett_views', function ($table): void {
        $table->dropIndex('host_partner_standalone');
    });
    (require PARTNERS_REF)->up();
    DB::table('scarlett_views')->insert($view('two'));

    expect(DB::table('scarlett_views')->where('partner_ref', 'acme')->count())->toBe(2);
});

it('does not refuse a composite unique index that merely includes partner_ref', function (): void {
    partnersMigrationViews();
    Schema::table('scarlett_views', function ($table): void {
        $table->string('partner_ref', 64)->nullable();
        $table->unique(['partner_ref', 'video_id'], 'host_partner_video_unique');
    });

    (require PARTNERS_REF)->up();

    expect(Schema::hasIndex('scarlett_views', 'scarlett_views_partner_ref_index'))->toBeTrue()
        ->and(Schema::hasIndex('scarlett_views', 'host_partner_video_unique'))->toBeTrue();
});

it('adds the column and the index again after a rollback, since no unique index can exist without the column', function (): void {
    partnersMigrationViews();

    (require PARTNERS_REF)->up();
    (require PARTNERS_REF)->down();

    // No column, so no unique index can exist and up() adds both.
    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeFalse();

    (require PARTNERS_REF)->up();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeTrue();
});

it('removes the column, every index on it and keeps partner rows after a successful up', function (string $start): void {
    partnersMigrationViews();
    (require PARTNERS_CREATE)->up();

    if ($start === 'ordinary') {
        Schema::table('scarlett_views', function ($table): void {
            $table->string('partner_ref', 64)->nullable();
            $table->index('partner_ref', 'host_partner_lookup');
        });
    }

    DB::table('scarlett_partners')->insert(partnersMigrationRow());

    (require PARTNERS_REF)->up();
    (require PARTNERS_REF)->down();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeFalse()
        ->and(partnersMigrationRefIndexNames())->toBe([])
        ->and(DB::table('scarlett_partners')->count())->toBe(1);
})->with(['absent', 'ordinary']);

it('keeps partner rows when migration 12 rolls back', function (): void {
    partnersMigrationViews();
    (require PARTNERS_CREATE)->up();
    (require PARTNERS_REF)->up();
    DB::table('scarlett_partners')->insert(partnersMigrationRow());
    DB::table('scarlett_partners')->where('ref', 'acme')->update(['revoked_at' => now()]);

    (require PARTNERS_REF)->down();

    expect(DB::table('scarlett_partners')->where('ref', 'acme')->whereNotNull('revoked_at')->count())->toBe(1);
});

it('rolls back migration 12 only for the last recorded copy', function (): void {
    partnersMigrationViews();
    (require PARTNERS_REF)->up();
    partnersMigrationRecord('2026_11_01_000001_add_partner_ref_to_scarlett_views_table', '2026_11_02_000001_add_partner_ref_to_scarlett_views_table');

    (require PARTNERS_REF)->down();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeTrue()
        ->and(Schema::hasIndex('scarlett_views', ['partner_ref']))->toBeTrue();

    DB::table('migrations')->where('migration', '2026_11_02_000001_add_partner_ref_to_scarlett_views_table')->delete();
    (require PARTNERS_REF)->down();

    expect(Schema::hasColumn('scarlett_views', 'partner_ref'))->toBeFalse();
});

it('rolls back migration 11 only for the last recorded copy', function (): void {
    (require PARTNERS_CREATE)->up();
    DB::table('scarlett_partners')->insert(partnersMigrationRow());
    partnersMigrationRecord('2026_11_01_000001_create_scarlett_partners_table', '2026_11_02_000001_create_scarlett_partners_table');

    // A later duplicate copy rolls back: the table and its rows stay.
    (require PARTNERS_CREATE)->down();

    expect(Schema::hasTable('scarlett_partners'))->toBeTrue()
        ->and(DB::table('scarlett_partners')->count())->toBe(1);

    DB::table('migrations')->where('migration', '2026_11_02_000001_create_scarlett_partners_table')->delete();
    (require PARTNERS_CREATE)->down();

    expect(Schema::hasTable('scarlett_partners'))->toBeFalse();
});

it('generates a random key_id that mass assignment cannot set, and regenerates it on rotate', function (): void {
    (require PARTNERS_CREATE)->up();

    $partner = Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://acme.test'], 'key_id' => str_repeat('f', 32)]);

    expect(PartnerParams::isKeyId($partner->key_id))->toBeTrue()
        ->and($partner->key_id)->not->toBe(str_repeat('f', 32));

    $first = $partner->key_id;
    $partner->rotate();

    expect($partner->fresh()->key_id)->not->toBe($first)
        ->and(PartnerParams::isKeyId($partner->fresh()->key_id))->toBeTrue()
        ->and($partner->fresh()->origins)->toBe(['https://acme.test']);
});

it('refuses an invalid ref and a rename', function (): void {
    (require PARTNERS_CREATE)->up();

    expect(fn () => Partner::create(['ref' => 'Acme TV', 'name' => 'Acme', 'origins' => []]))
        ->toThrow(InvalidPartnerException::class);

    $partner = Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => []]);

    expect(fn () => $partner->update(['ref' => 'renamed']))->toThrow(InvalidPartnerException::class, 'analytics identity');
    expect(Partner::query()->value('ref'))->toBe('acme');
});

it('reads revoked and expired state, expiry being exclusive of the boundary instant', function (): void {
    (require PARTNERS_CREATE)->up();
    $now = Date::parse('2026-11-10 12:00:00');
    $partner = new Partner(['ref' => 'acme', 'name' => 'Acme', 'origins' => []]);

    expect($partner->isRevoked())->toBeFalse()->and($partner->isExpired($now))->toBeFalse()->and($partner->isActive($now))->toBeTrue();

    $partner->expires_at = $now->copy()->addSecond();
    expect($partner->isExpired($now))->toBeFalse();

    $partner->expires_at = $now->copy();
    expect($partner->isExpired($now))->toBeTrue()->and($partner->isActive($now))->toBeFalse();

    $partner->expires_at = null;
    $partner->revoked_at = $now;
    expect($partner->isRevoked())->toBeTrue()->and($partner->isActive($now))->toBeFalse();
});

it('builds a partner context from a row, treating malformed origins as none', function (): void {
    (require PARTNERS_CREATE)->up();
    $partner = Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://a.test', 7]]);

    $gate = app(PartnerGate::class);
    $context = $gate->context($partner);

    // A list member is kept as stored, like the 7: the frame-ancestors path validates
    // every member again.
    expect($context->ref)->toBe('acme')
        ->and($context->keyId)->toBe($partner->key_id)
        ->and($context->origins)->toBe(['https://a.test', 7])
        ->and(PartnerOrigin::validOnly($context->origins))->toBe(['https://a.test']);

    // A JSON object is no list, whatever its keys: no origins at all.
    foreach (['{"a":1}', '{"0":"https://a.test"}'] as $raw) {
        DB::table('scarlett_partners')->where('id', $partner->id)->update(['origins' => $raw]);

        expect($gate->context(Partner::query()->firstOrFail())->origins)->toBe([]);
    }
});

// MySQL and PostgreSQL reject invalid JSON at write time, so that state cannot exist there.
it('treats stored origins text that is not JSON at all as none', function (): void {
    (require PARTNERS_CREATE)->up();
    $partner = Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://a.test']]);

    DB::table('scarlett_partners')->where('id', $partner->id)->update(['origins' => '{not json']);

    expect(app(PartnerGate::class)->context(Partner::query()->firstOrFail())->origins)->toBe([]);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'MySQL and PostgreSQL reject invalid JSON at write time, so that state cannot exist there.');

it('drops the table on rollback when no migration is recorded at all', function (): void {
    (require PARTNERS_CREATE)->up();

    (require PARTNERS_CREATE)->down();

    expect(Schema::hasTable('scarlett_partners'))->toBeFalse();
});
