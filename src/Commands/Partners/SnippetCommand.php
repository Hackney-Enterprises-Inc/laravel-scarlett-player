<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

use Hei\ScarlettPlayer\Exceptions\InvalidEmbedConfigException;
use Hei\ScarlettPlayer\Exceptions\ScarlettPlayerException;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Console\Command;

/**
 * Prints a partner's embed snippet (or, with --url, the bare page URL). This output is
 * the only place a signed URL appears: nothing is logged or stored.
 *
 * The URL is signed relative and prefixed with --root (default app.url), so a wrong or
 * differently schemed APP_URL cannot invalidate it. Refusals (always_sign off, a bad
 * media id, protected media without --expires, an expiry beyond 90 days, an unknown,
 * revoked or expired partner) come from the minter and are printed as errors.
 */
class SnippetCommand extends Command
{
    use NormalisesInput;

    protected $signature = 'scarlett:partners:snippet
        {ref : The partner ref}
        {media : The media id}
        {--width=640 : Iframe width}
        {--height=360 : Iframe height}
        {--url : Print only the signed page URL}
        {--expires= : Make the URL expire: a date or a number of days from now, at most 90 days. Required for protected media}
        {--root= : Origin (and sub-path) the URL is prefixed with, default app.url}';

    protected $description = 'Print the embed snippet for a Scarlett partner and a media';

    public function handle(ScarlettPlayer $player, EmbedConfig $embedConfig): int
    {
        $ref = $this->text($this->argument('ref'));
        $media = $this->text($this->argument('media'));
        $root = $this->text($this->option('root'));
        $root = $root !== '' ? $root : null;

        try {
            $expires = CreateCommand::moment($this->option('expires'));

            $output = $this->option('url')
                ? $player->partnerEmbedUrl($ref, $media, $expires, $root)
                : $player->partnerEmbedCode($ref, $media, $expires, $this->dimension('width', 640), $this->dimension('height', 360), $root);
        } catch (ScarlettPlayerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $unrestricted = $embedConfig->allowedDomains() === [];
        } catch (InvalidEmbedConfigException) {
            // A malformed list: the page falls back to '*' as well, so warn the same way.
            $unrestricted = true;
        }

        if ($unrestricted) {
            // On stderr, so --url output can still be piped.
            $this->output->getErrorStyle()->warning('WARNING: embed.allowed_domains is empty, so non-partner embeds are still framed by *. The partner lock is per deployment: set allowed_domains to your own sites.');
        }

        $this->line($output);

        return self::SUCCESS;
    }

    private function dimension(string $name, int $default): int|string
    {
        $value = $this->text($this->option($name));

        return $value !== '' ? $value : $default;
    }
}
