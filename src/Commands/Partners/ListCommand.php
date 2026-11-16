<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

use Hei\ScarlettPlayer\Models\Partner;
use Illuminate\Console\Command;

/**
 * Lists partners: ref, name, origins, state and contract end. Never the key id, and
 * never a URL or signature.
 */
class ListCommand extends Command
{
    protected $signature = 'scarlett:partners:list';

    protected $description = 'List Scarlett embed partners';

    public function handle(): int
    {
        $partners = Partner::query()->orderBy('ref')->get();

        if ($partners->isEmpty()) {
            $this->line('No partners. Create one with: php artisan scarlett:partners:create <ref> --origin=https://partner.example');

            return self::SUCCESS;
        }

        $this->table(
            ['Ref', 'Name', 'Origins', 'State', 'Expires'],
            $partners->map(fn (Partner $partner): array => [
                $partner->ref,
                $partner->name,
                implode("\n", array_map(fn (mixed $o): string => is_string($o) ? $o : '(invalid)', is_array($partner->origins) ? $partner->origins : [])),
                match (true) {
                    $partner->isRevoked() => 'revoked',
                    $partner->isExpired() => 'expired',
                    default => 'active',
                },
                $partner->expires_at?->toDateString() ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
