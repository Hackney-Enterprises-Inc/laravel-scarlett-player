<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Revokes a partner: its pages answer 403 from the next load. --restore un-revokes AND
 * rotates the key id, so the snippets the revoke killed stay dead and the partner needs
 * a new one.
 */
class RevokeCommand extends Command
{
    use NormalisesInput;

    protected $signature = 'scarlett:partners:revoke
        {ref : The partner ref}
        {--restore : Un-revoke the partner and rotate its key, so old snippets stay dead}';

    protected $description = 'Revoke (or restore) a Scarlett embed partner';

    public function handle(): int
    {
        $ref = $this->text($this->argument('ref'));
        $partner = Partner::query()->where('ref', $ref)->first();

        if ($partner === null) {
            $this->error(InvalidPartnerException::unknown($ref)->getMessage());

            return self::FAILURE;
        }

        if ($this->option('restore')) {
            if (! $partner->isRevoked()) {
                $this->error("Partner [{$ref}] is not revoked.");

                return self::FAILURE;
            }

            $partner->revoked_at = null;
            $partner->rotate();

            $this->info("Partner [{$ref}] restored with a new key. Snippets minted before the revoke stay dead: mint a new one with scarlett:partners:snippet.");

            return self::SUCCESS;
        }

        if ($partner->isRevoked()) {
            $this->line("Partner [{$ref}] is already revoked.");

            return self::SUCCESS;
        }

        $partner->revoked_at = Date::now();
        $partner->save();

        $this->info("Partner [{$ref}] revoked: its pages answer 403 from the next load.");

        return self::SUCCESS;
    }
}
