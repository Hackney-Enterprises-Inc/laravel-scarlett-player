<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor;

/**
 * What one doctor check found. Messages name config keys, never secret values.
 */
final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
    ) {}

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warn(string $message): self
    {
        return new self(CheckStatus::Warn, $message);
    }

    public static function fail(string $message): self
    {
        return new self(CheckStatus::Fail, $message);
    }
}
