<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconPartnerColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Models\Partner;
use Illuminate\Support\Facades\Schema;

/*
 * The partner column check never throws: no partners table, no partners, or a table
 * without partner_ref are each a result, never an exception.
 */

beforeEach(function (): void {
    $this->usesMigrations();
});

function partnerColumnsCheck(): CheckResult
{
    return app(BeaconPartnerColumnsCheck::class)->run();
}

it('passes when the partners table does not exist', function (): void {
    Schema::drop('scarlett_partners');

    expect(partnerColumnsCheck()->status)->toBe(CheckStatus::Pass);
});

it('passes when no partner exists, with or without the column', function (): void {
    expect(partnerColumnsCheck()->status)->toBe(CheckStatus::Pass);

    Schema::table('scarlett_views', function ($table): void {
        $table->dropIndex('scarlett_views_partner_ref_index');
    });
    Schema::table('scarlett_views', function ($table): void {
        $table->dropColumn('partner_ref');
    });

    expect(partnerColumnsCheck()->status)->toBe(CheckStatus::Pass);
});

it('warns when partners exist and partner_ref is missing', function (): void {
    Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://acme.test']]);
    Schema::table('scarlett_views', function ($table): void {
        $table->dropIndex('scarlett_views_partner_ref_index');
    });
    Schema::table('scarlett_views', function ($table): void {
        $table->dropColumn('partner_ref');
    });

    $result = partnerColumnsCheck();

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('scarlett_views.partner_ref', '--tag=scarlett-migrations-partner-ref');
});

it('passes when partners exist and the column is there', function (): void {
    Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://acme.test']]);

    expect(partnerColumnsCheck()->status)->toBe(CheckStatus::Pass);
});

it('does not throw when scarlett_views is missing too', function (): void {
    Partner::create(['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://acme.test']]);
    Schema::drop('scarlett_views');

    expect(fn () => partnerColumnsCheck())->not->toThrow(Throwable::class);
});
