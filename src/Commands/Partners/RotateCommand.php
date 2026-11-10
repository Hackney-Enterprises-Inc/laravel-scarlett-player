<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Models\Partner;
use Illuminate\Console\Command;

/**
 * Regenerates a partner's key id: every snippet minted before answers 403.
 */
class RotateCommand extends Command
{
    protected $signature = 'scarlett:partners:rotate {ref : The partner ref}';

    protected $description = 'Rotate a Scarlett embed partner key, so existing snippets stop working';

    public function handle(): int
    {
        $ref = $this->argument('ref');
        $ref = is_string($ref) ? $ref : '';
        $partner = Partner::query()->where('ref', $ref)->first();

        if ($partner === null) {
            $this->error(InvalidPartnerException::unknown($ref)->getMessage());

            return self::FAILURE;
        }

        $partner->rotate();

        $this->info("Partner [{$ref}] rotated: every earlier snippet answers 403. Mint a new one with scarlett:partners:snippet.");

        return self::SUCCESS;
    }
}
