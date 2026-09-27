<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;

/**
 * `<string column> = <key column>`, compiled by the grammar that runs it, with the key
 * cast to text only where the engine needs it.
 *
 * Both sides are column names, wrapped by that grammar at compile time, so nothing but
 * identifiers ever reaches the SQL.
 *
 * - Postgres refuses varchar = integer, so the key is cast: `::text` (never
 *   CAST(... AS CHAR), which is character(1) there and truncates).
 * - SQL Server gets CAST(... AS NVARCHAR(191)).
 * - MySQL and SQLite compare the columns as they are. MySQL converts varchar and integer
 *   to numbers natively, and a CAST(... AS CHAR) against a host table with another
 *   collation raises "Illegal mix of collations"; SQLite needs nothing.
 */
final readonly class TextKeyComparison implements Expression
{
    public function __construct(
        private string $stringColumn,
        private string $keyColumn,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        $key = $grammar->wrap($this->keyColumn);

        $cast = match (true) {
            $grammar instanceof PostgresGrammar => "{$key}::text",
            $grammar instanceof SqlServerGrammar => "CAST({$key} AS NVARCHAR(191))",
            default => $key,
        };

        return $grammar->wrap($this->stringColumn).' = '.$cast;
    }
}
