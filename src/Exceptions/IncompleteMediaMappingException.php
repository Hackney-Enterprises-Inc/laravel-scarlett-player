<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * The host has not told the package enough to build a MediaSource. Protection fails
 * closed: a missing is_protected mapping lands here, never as "unprotected".
 */
class IncompleteMediaMappingException extends ScarlettPlayerException
{
    /**
     * @param  list<string>  $missing  The config keys (or requirements) that are missing.
     */
    public function __construct(
        string $message,
        public readonly array $missing = [],
    ) {
        parent::__construct($message);
    }

    public static function modelNotConfigured(): self
    {
        return new self(
            'Scarlett media mapping is incomplete: set scarlett-player.media.model, or bind your own ResolvesMedia.',
            ['media.model'],
        );
    }

    public static function modelClassMissing(string $class): self
    {
        return new self(
            "Scarlett media mapping is incomplete: media.model [{$class}] is not an Eloquent model class.",
            ['media.model'],
        );
    }

    /**
     * @param  list<string>  $keys  Attribute map keys that are missing.
     */
    public static function missingAttributes(string $class, array $keys): self
    {
        $list = implode(', ', array_map(fn (string $key): string => "media.attributes.{$key}", $keys));

        return new self(
            "Scarlett media mapping for [{$class}] is incomplete: implement ".
            'Hei\\ScarlettPlayer\\Contracts\\ScarlettMedia on the model, or map '.$list.'.',
            array_map(fn (string $key): string => "media.attributes.{$key}", $keys),
        );
    }

    /**
     * The mapping names a column, but the record holds nothing usable in it for a
     * required attribute. The package refuses to guess.
     */
    public static function missingValue(string $class, string $mediaId, string $column, string $key): self
    {
        return new self(
            "Scarlett media [{$mediaId}] of [{$class}] has no usable value in column [{$column}] ".
            "for the required attribute {$key}; the package never assumes a default.",
            ["media.attributes.{$key}"],
        );
    }
}
