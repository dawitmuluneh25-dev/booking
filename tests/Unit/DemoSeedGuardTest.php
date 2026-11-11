<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\DemoSeedGuard;
use App\Database\DemoSeedRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The seed's two locks, tested where they can fail cheaply.
 *
 * The property that matters most is the asymmetry: confirmation can be given, the
 * environment cannot be argued with. A test that only checked "production refuses"
 * would pass even if `--force` overrode it, so the pair is asserted together.
 */
final class DemoSeedGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function productionEnvironments(): array
    {
        return [
            'production' => ['production'],
            'upper case' => ['PRODUCTION'],
            'padded' => ['  production  '],
            'prod' => ['prod'],
        ];
    }

    #[DataProvider('productionEnvironments')]
    public function testProductionIsRefused(string $environment): void
    {
        $guard = new DemoSeedGuard($environment);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessage('APP_ENV is');

        $guard->requirePermittedEnvironment();
    }

    public function testProductionRefusesWithItsOwnReasonNotAGenericOne(): void
    {
        $guard = new DemoSeedGuard('production');

        try {
            $guard->requirePermittedEnvironment();
            self::fail('The seed permitted a production database.');
        } catch (DemoSeedRefused $refusal) {
            self::assertStringContainsString('must never be written', $refusal->getMessage());
            self::assertStringContainsString('No flag overrides this', $refusal->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function permittedEnvironments(): array
    {
        return [
            'local' => ['local'],
            'development' => ['development'],
            'staging' => ['staging'],
            'testing' => ['testing'],
            'padded and upper case' => [' Local '],
        ];
    }

    #[DataProvider('permittedEnvironments')]
    public function testDevelopmentEnvironmentsArePermitted(string $environment): void
    {
        $guard = new DemoSeedGuard($environment);

        $this->expectNotToPerformAssertions();

        $guard->requirePermittedEnvironment();
    }

    /**
     * An unrecognised name is refused rather than permitted. Config falls back to
     * "production" when APP_ENV is unset, but a typo such as "prodution" must not
     * become a way in.
     */
    public function testAnUnrecognisedEnvironmentIsRefused(): void
    {
        $guard = new DemoSeedGuard('prodution');

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessage('not a known');

        $guard->requirePermittedEnvironment();
    }

    public function testEmptyEnvironmentIsRefused(): void
    {
        $guard = new DemoSeedGuard('');

        $this->expectException(DemoSeedRefused::class);

        $guard->requirePermittedEnvironment();
    }

    public function testUnconfirmedRunIsRefused(): void
    {
        $guard = new DemoSeedGuard('local');

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessage('explicit confirmation');

        $guard->requireConfirmation(false);
    }

    public function testConfirmedRunProceeds(): void
    {
        $guard = new DemoSeedGuard('local');

        $this->expectNotToPerformAssertions();

        $guard->requireConfirmation(true);
    }

    /**
     * The order the console uses: a production database is reported as production
     * even when the operator forgot the flag, because that is the reason that
     * matters and the one that cannot be fixed by retyping the command.
     */
    public function testEnvironmentIsReportedBeforeMissingConfirmation(): void
    {
        $guard = new DemoSeedGuard('production');

        try {
            $guard->requirePermittedEnvironment();
            $guard->requireConfirmation(false);
            self::fail('The seed permitted a production database.');
        } catch (DemoSeedRefused $refusal) {
            self::assertStringContainsString('APP_ENV', $refusal->getMessage());
            self::assertStringNotContainsString('explicit confirmation', $refusal->getMessage());
        }
    }
}
