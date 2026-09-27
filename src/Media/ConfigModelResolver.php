<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Media;

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Contracts\ScarlettMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\IncompleteMediaMappingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Stringable;

/**
 * The default ResolvesMedia: loads scarlett-player.media.model by media.key and then
 * requires one of two routes. Either the model implements ScarlettMedia, or
 * media.attributes maps playback_url, is_live and is_protected. Anything less throws;
 * protection metadata fails closed and is never assumed.
 */
class ConfigModelResolver implements ResolvesMedia
{
    /**
     * Attribute map keys that must be mapped when the model does not implement ScarlettMedia.
     */
    public const REQUIRED_ATTRIBUTES = ['playback_url', 'is_live', 'is_protected'];

    /**
     * Prefix that marks a source_disk mapping as a literal disk name rather than a column.
     */
    public const DISK_LITERAL_PREFIX = 'disk:';

    /** Postgres: a value the column type cannot parse (an id that is not a uuid). */
    private const INVALID_TEXT_REPRESENTATION = '22P02';

    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Resolve a host media id to a MediaSource, or null when no record matches.
     *
     * @throws IncompleteMediaMappingException when the mapping, or the record, cannot say
     *                                         what the playback URL, live flag or protection flag is.
     */
    public function resolve(string $mediaId): ?MediaSource
    {
        $class = $this->modelClass();
        $attributes = $this->attributeMap();

        if (! is_subclass_of($class, ScarlettMedia::class)) {
            $this->assertRequiredAttributesMapped($class, $attributes);
        }

        $prototype = new $class;
        $key = $this->config->get('scarlett-player.media.key');
        $key = is_string($key) && $key !== '' ? $key : $prototype->getRouteKeyName();

        $model = $this->find($prototype, $key, $mediaId);

        if ($model === null) {
            return null;
        }

        if ($model instanceof ScarlettMedia) {
            return $model->toScarlettMediaSource();
        }

        return $this->fromAttributeMap($mediaId, $model, $attributes);
    }

    /**
     * The record whose key column holds this id, or null.
     *
     * An id the column type cannot hold is unknown, not an error: Postgres refuses to
     * compare a native uuid column with 'no-such-id' (SQLSTATE 22P02) where MySQL and
     * SQLite simply match nothing. Inside an open transaction the lookup runs in a
     * savepoint, because a failed statement would otherwise abort the host's transaction.
     */
    private function find(Model $prototype, string $key, string $mediaId): ?Model
    {
        $lookup = fn (): ?Model => $prototype->newQuery()->where($key, $mediaId)->first();
        $connection = $prototype->getConnection();

        try {
            return $connection->transactionLevel() > 0 ? $connection->transaction($lookup) : $lookup();
        } catch (QueryException $e) {
            if ($e->getCode() === self::INVALID_TEXT_REPRESENTATION) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Check the configuration without loading a record, for scarlett:doctor.
     *
     * Passes when media.model names an Eloquent model that either implements ScarlettMedia
     * or has playback_url, is_live and is_protected mapped in media.attributes.
     *
     * @throws IncompleteMediaMappingException naming what is missing.
     */
    public function assertMappingComplete(): void
    {
        $class = $this->modelClass();

        if (is_subclass_of($class, ScarlettMedia::class)) {
            return;
        }

        $this->assertRequiredAttributesMapped($class, $this->attributeMap());
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(): string
    {
        $class = $this->config->get('scarlett-player.media.model');

        if (! is_string($class) || $class === '') {
            throw IncompleteMediaMappingException::modelNotConfigured();
        }

        if (! is_subclass_of($class, Model::class)) {
            throw IncompleteMediaMappingException::modelClassMissing($class);
        }

        return $class;
    }

    /**
     * The attribute map with unmapped (null, empty or non-string) entries dropped.
     *
     * @return array<string, string>
     */
    private function attributeMap(): array
    {
        $map = $this->config->get('scarlett-player.media.attributes', []);

        if (! is_array($map)) {
            return [];
        }

        $mapped = [];

        foreach ($map as $name => $column) {
            if (is_string($name) && is_string($column) && $column !== '') {
                $mapped[$name] = $column;
            }
        }

        return $mapped;
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, string>  $attributes
     */
    private function assertRequiredAttributesMapped(string $class, array $attributes): void
    {
        $missing = array_values(array_diff(self::REQUIRED_ATTRIBUTES, array_keys($attributes)));

        if ($missing !== []) {
            throw IncompleteMediaMappingException::missingAttributes($class, $missing);
        }
    }

    /**
     * @param  array<string, string>  $attributes  Already checked to hold the required keys.
     */
    private function fromAttributeMap(string $mediaId, Model $model, array $attributes): MediaSource
    {
        $playbackUrl = $this->stringValue($model, $attributes, 'playback_url');

        if ($playbackUrl === null) {
            throw IncompleteMediaMappingException::missingValue($model::class, $mediaId, $attributes['playback_url'], 'playback_url');
        }

        $duration = $this->value($model, $attributes, 'duration');

        return new MediaSource(
            id: $mediaId,
            playbackUrl: $playbackUrl,
            isLive: $this->boolValue($model, $mediaId, $attributes, 'is_live'),
            isProtected: $this->boolValue($model, $mediaId, $attributes, 'is_protected'),
            duration: is_numeric($duration) ? (float) $duration : null,
            sourceDisk: $this->sourceDisk($model, $attributes),
            sourcePath: $this->stringValue($model, $attributes, 'source_path'),
            title: $this->stringValue($model, $attributes, 'title'),
            poster: $this->stringValue($model, $attributes, 'poster'),
            model: $model,
        );
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function value(Model $model, array $attributes, string $name): mixed
    {
        return isset($attributes[$name]) ? $model->getAttribute($attributes[$name]) : null;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function stringValue(Model $model, array $attributes, string $name): ?string
    {
        $value = $this->value($model, $attributes, $name);

        if (is_scalar($value) && ! is_bool($value) && (string) $value !== '') {
            return (string) $value;
        }

        if ($value instanceof Stringable && (string) $value !== '') {
            return (string) $value;
        }

        return null;
    }

    /**
     * A required flag. A null or unreadable value throws: the record has not said, and the
     * package never guesses (a null is_protected is not "unprotected").
     *
     * @param  array<string, string>  $attributes
     */
    private function boolValue(Model $model, string $mediaId, array $attributes, string $name): bool
    {
        $value = $this->value($model, $attributes, $name);

        $flag = match (true) {
            is_bool($value) => $value,
            // filter_var reads '' as false; an empty string has not said "unprotected".
            is_string($value) && trim($value) === '' => null,
            is_int($value), is_string($value), is_float($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default => null,
        };

        if ($flag === null) {
            throw IncompleteMediaMappingException::missingValue($model::class, $mediaId, $attributes[$name], $name);
        }

        return $flag;
    }

    /**
     * source_disk is either a literal ('disk:mezzanine') or a column name.
     *
     * @param  array<string, string>  $attributes
     */
    private function sourceDisk(Model $model, array $attributes): ?string
    {
        $mapping = $attributes['source_disk'] ?? null;

        if ($mapping !== null && str_starts_with($mapping, self::DISK_LITERAL_PREFIX)) {
            $disk = substr($mapping, strlen(self::DISK_LITERAL_PREFIX));

            return $disk !== '' ? $disk : null;
        }

        return $this->stringValue($model, $attributes, 'source_disk');
    }
}
