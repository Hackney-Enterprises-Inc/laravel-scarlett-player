<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Models\Partner;
use Illuminate\Console\Command;

/**
 * Changes a partner's name, origins (the whole list is replaced) or contract end. It
 * never touches the ref or the key id: existing snippets keep working.
 */
class UpdateCommand extends Command
{
    protected $signature = 'scarlett:partners:update
        {ref : The partner ref}
        {--name= : New display name}
        {--origin=* : Replaces every allowed origin, repeatable}
        {--expires= : New contract end: a date or a number of days from now}
        {--clear-expires : Remove the contract end}';

    protected $description = 'Update a Scarlett embed partner';

    public function handle(): int
    {
        $ref = $this->argument('ref');
        $ref = is_string($ref) ? $ref : '';
        $partner = Partner::query()->where('ref', $ref)->first();

        if ($partner === null) {
            $this->error(InvalidPartnerException::unknown($ref)->getMessage());

            return self::FAILURE;
        }

        $name = $this->option('name');
        $origin = $this->option('origin');
        $clear = (bool) $this->option('clear-expires');

        if ($clear && $this->option('expires') !== null) {
            $this->error('Use --expires or --clear-expires, not both.');

            return self::FAILURE;
        }

        try {
            $origins = is_array($origin) && $origin !== [] ? CreateCommand::origins($origin) : null;
            $expires = CreateCommand::moment($this->option('expires'));
        } catch (InvalidPartnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($origins === null && $expires === null && ! $clear && ! (is_string($name) && $name !== '')) {
            $this->error('Nothing to update: give --name, --origin, --expires or --clear-expires.');

            return self::FAILURE;
        }

        if (is_string($name) && $name !== '') {
            $partner->name = $name;
        }

        if ($origins !== null) {
            $partner->origins = $origins;
        }

        if ($expires !== null) {
            $partner->expires_at = $expires;
        } elseif ($clear) {
            $partner->expires_at = null;
        }

        $partner->save();

        $this->info("Partner [{$ref}] updated.");

        return self::SUCCESS;
    }
}
