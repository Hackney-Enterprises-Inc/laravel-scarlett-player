<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A morph-many whose morph id column is a string (so uuid and ulid host keys fit), on a
 * parent whose key may be an integer.
 *
 * Postgres refuses `varchar = integer`, so every comparison is made as text: the parent
 * key values bound for lazy and eager loading are cast to strings in PHP (and bound, never
 * inlined as raw integers), and the existence and aggregate queries (has(), whereHas(),
 * withCount()) compare through TextKeyComparison, which casts the parent key column to
 * text on Postgres (and SQL Server) and compares the columns as they are on MySQL and
 * SQLite. The local key stays the real key column.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
class StringKeyedMorphMany extends MorphMany
{
    /**
     * The parent key as a string, for the lazy constraint and for create().
     */
    public function getParentKey(): ?string
    {
        $key = parent::getParentKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * Parent key values for eager loading, as strings.
     *
     * @param  array<int, TDeclaringModel>  $models
     * @param  string|null  $key
     * @return array<int, string>
     */
    protected function getKeys(array $models, $key = null)
    {
        return array_values(array_map('strval', array_filter(parent::getKeys($models, $key), fn (mixed $value): bool => $value !== null)));
    }

    /**
     * Always a bound whereIn: the default whereIntegerInRaw would inline integers.
     *
     * @param  string  $key
     */
    protected function whereInMethod(Model $model, $key): string
    {
        return 'whereIn';
    }

    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  mixed  $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query->select($columns)->whereRaw(
            new TextKeyComparison($this->getExistenceCompareKey(), $this->getQualifiedParentKeyName()),
        );

        return $query->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }
}
