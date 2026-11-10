<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

use Carbon\Exceptions\InvalidFormatException;
use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerOrigin;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Creates a partner: a ref, the origins allowed to frame its embeds and an optional
 * contract end. Prints no key id: the signed snippet from scarlett:partners:snippet is
 * the only place a secret-bearing URL appears.
 */
class CreateCommand extends Command
{
    protected $signature = 'scarlett:partners:create
        {ref : Lowercase slug, at most 64 characters (the analytics identity; it cannot be renamed)}
        {--name= : Display name, defaults to the ref}
        {--origin=* : An origin allowed to frame the embed, repeatable: https://host, https://host:port or https://*.host}
        {--expires= : When the partnership ends: a date or a number of days from now}';

    protected $description = 'Create a Scarlett embed partner';

    public function handle(): int
    {
        $ref = $this->argument('ref');
        $ref = is_string($ref) ? $ref : '';

        try {
            if (! PartnerParams::isRef($ref)) {
                throw InvalidPartnerException::invalidRef($ref);
            }

            $origins = self::origins($this->option('origin'));
            $expires = self::moment($this->option('expires'));
        } catch (InvalidPartnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (Partner::query()->where('ref', $ref)->exists()) {
            $this->error("A partner with ref [{$ref}] already exists. Use scarlett:partners:update, or rotate or revoke it.");

            return self::FAILURE;
        }

        $name = $this->option('name');

        Partner::query()->create([
            'ref' => $ref,
            'name' => is_string($name) && $name !== '' ? $name : $ref,
            'origins' => $origins,
            'expires_at' => $expires,
        ]);

        $this->info("Partner [{$ref}] created. Mint its snippet with: php artisan scarlett:partners:snippet {$ref} <media>");

        return self::SUCCESS;
    }

    /**
     * The canonical, validated origins of a repeatable --origin option. At least one is required.
     *
     * @return list<string>
     *
     * @throws InvalidPartnerException
     */
    public static function origins(mixed $option): array
    {
        $given = is_array($option) ? array_values(array_filter($option, fn (mixed $o): bool => $o !== null && $o !== '')) : [];

        if ($given === []) {
            throw new InvalidPartnerException('At least one --origin is required, for example --origin=https://partner.example.');
        }

        return PartnerOrigin::normaliseAll($given);
    }

    /**
     * An option value as a future moment: whole days from now when digits, else a date.
     * Null when the option was not given.
     *
     * @throws InvalidPartnerException when it is not a date, or is not in the future.
     */
    public static function moment(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidPartnerException('--expires takes a date or a number of days.');
        }

        try {
            $at = ctype_digit($value) ? Carbon::now()->addDays((int) $value) : Carbon::parse($value);
        } catch (InvalidFormatException) {
            throw new InvalidPartnerException('--expires ['.substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 40).'] is not a date: use 2027-01-31 or a number of days.');
        }

        if ($at->lessThanOrEqualTo(Carbon::now())) {
            throw new InvalidPartnerException('--expires must be in the future.');
        }

        return $at;
    }
}
