<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Relations\TextKeyComparison;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;

function compiledOn(Connection $connection): string
{
    return (new TextKeyComparison('scarlett_clips.clippable_id', 'videos.id'))->getValue($connection->getQueryGrammar());
}

test('Postgres casts the key with ::text, never CAST AS CHAR', function (): void {
    expect(compiledOn(new PostgresConnection(fn () => null)))
        ->toBe('"scarlett_clips"."clippable_id" = "videos"."id"::text');
});

test('MySQL compares the columns as they are, with no cast that could clash on collation', function (): void {
    expect(compiledOn(new MySqlConnection(fn () => null)))
        ->toBe('`scarlett_clips`.`clippable_id` = `videos`.`id`');
});

test('SQLite compares the columns as they are', function (): void {
    expect(compiledOn(new SQLiteConnection(fn () => null)))
        ->toBe('"scarlett_clips"."clippable_id" = "videos"."id"');
});

test('SQL Server casts to NVARCHAR', function (): void {
    expect(compiledOn(new SqlServerConnection(fn () => null)))
        ->toBe('[scarlett_clips].[clippable_id] = CAST([videos].[id] AS NVARCHAR(191))');
});
