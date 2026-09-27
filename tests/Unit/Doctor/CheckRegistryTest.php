<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Exceptions\InvalidDoctorCheckException;
use Hei\ScarlettPlayer\Exceptions\ScarlettPlayerException;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\PassingCheck;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ThrowingCheck;

it('keeps registration order and never adds a class twice', function (): void {
    $registry = new CheckRegistry(app());

    $registry->add(PassingCheck::class, ThrowingCheck::class)->add(PassingCheck::class);

    expect($registry->classes())->toBe([PassingCheck::class, ThrowingCheck::class]);
});

it('resolves the registered checks from the container', function (): void {
    $checks = (new CheckRegistry(app()))->add(PassingCheck::class)->checks();

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(PassingCheck::class);
});

it('throws a named exception for a class that is not a Check', function (): void {
    /** @phpstan-ignore argument.type */
    (new CheckRegistry(app()))->add(stdClass::class)->checks();
})->throws(InvalidDoctorCheckException::class, 'stdClass');

it('makes that exception a ScarlettPlayerException', function (): void {
    expect(InvalidDoctorCheckException::notACheck('X'))->toBeInstanceOf(ScarlettPlayerException::class);
});

it('builds results with the three statuses', function (): void {
    expect(CheckResult::pass('a'))->status->toBe(CheckStatus::Pass)
        ->and(CheckResult::warn('b'))->status->toBe(CheckStatus::Warn)
        ->and(CheckResult::fail('c'))->message->toBe('c')
        ->and(CheckResult::fail('c')->status)->toBe(CheckStatus::Fail);
});
