<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CredentialInventory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * D-10's credential register, and the two guards that keep it from becoming decoration.
 *
 * The interesting tests here are not the date arithmetic — that is arithmetic — but the drift guards:
 * one that fails when the code reads an environment key the register does not classify, and one that
 * fails when `.env.example` documents a key the register does not know. Without them this file would
 * describe the credentials that existed on 24 November 2026, which is the failure mode every inventory
 * in this project has had to be taught to avoid.
 */
final class CredentialInventoryTest extends TestCase
{
    private const TODAY = '2026-11-24';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'key' => 'DB_PASSWORD',
            'source' => 'env',
            'kind' => 'database',
            'function' => 'runtime database user',
            'office' => 'Engineering Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $credentials
     * @param array<string, string>      $nonSecret
     */
    private function inventory(array $credentials = [], array $nonSecret = []): CredentialInventory
    {
        return new CredentialInventory($credentials, $nonSecret);
    }

    private function register(): CredentialInventory
    {
        return CredentialInventory::fromFile(
            dirname(__DIR__, 2) . '/database/operations/credential-inventory.php'
        );
    }

    /**
     * @param list<string> $configured
     *
     * @return array{
     *     key: string,
     *     kind: string,
     *     office: string,
     *     custodian: ?string,
     *     state: string,
     *     detail: string,
     *     due_on: ?string,
     *     days_remaining: ?int
     * }
     */
    private function row(CredentialInventory $inventory, string $key, array $configured = []): array
    {
        foreach ($inventory->report(self::TODAY, $configured) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        self::fail('No credential row for "' . $key . '".');
    }

    public function testTheRegisterOnDiskLoadsAndEverySecretHasAnAccountableOffice(): void
    {
        $inventory = $this->register();

        self::assertGreaterThanOrEqual(8, count($inventory->credentials()));

        foreach ($inventory->credentials() as $entry) {
            self::assertNotSame('', $entry['office'], $entry['key'] . ' has no office.');
            self::assertNotSame('', $entry['function'], $entry['key'] . ' does not say what it does.');
        }
    }

    public function testTheBackupKeyIsRegisteredWithoutACalendarInterval(): void
    {
        $inventory = $this->register();
        $found = null;

        foreach ($inventory->credentials() as $entry) {
            if ($entry['kind'] === 'backup-key') {
                $found = $entry;
            }
        }

        self::assertNotNull($found, 'The backup key is not in the register.');
        self::assertNull(
            $found['interval_days'],
            'A calendar interval on the backup key would strand the archives it encrypted.'
        );
    }

    /**
     * Every environment key the code reads must be classified.
     *
     * This is the guard that makes the register complete rather than merely accurate: a developer who
     * adds `Env::get('NEW_API_KEY')` and forgets the register gets a red build here, not a second
     * undocumented secret beside the first.
     */
    public function testEveryEnvironmentKeyTheCodeReadsIsClassified(): void
    {
        $root = dirname(__DIR__, 2);
        $read = [];
        $pattern = '/Env::(?:get|bool|int)\(\s*\'([A-Z0-9_]+)\'/';

        foreach (['src', 'bin', 'routes', 'public'] as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (preg_match_all($pattern, $contents, $matches) > 0) {
                    foreach ($matches[1] as $key) {
                        $read[$key] = true;
                    }
                }
            }
        }

        self::assertNotSame([], $read, 'The scan found no Env:: reads at all; the pattern has drifted.');

        $classified = $this->register()->classifiedEnvKeys();
        $unclassified = array_values(array_diff(array_keys($read), $classified));

        self::assertSame(
            [],
            $unclassified,
            'Read by the code and absent from the register: ' . implode(', ', $unclassified)
                . '. Add each one to database/operations/credential-inventory.php.'
        );
    }

    /** A key in the register that nothing reads is dead weight the next reader has to disprove. */
    public function testTheRegisterDoesNotNameAKeyTheCodeNeverReads(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '';

        foreach (['src', 'bin'] as $directory) {
            /** @var SplFileInfo $file */
            foreach (
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file
            ) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $code .= (string) file_get_contents($file->getPathname());
                }
            }
        }

        foreach ($this->register()->secretEnvKeys() as $key) {
            self::assertStringContainsString(
                "'" . $key . "'",
                $code,
                'The register lists ' . $key . ' but no code reads it.'
            );
        }
    }

    /** What `.env.example` documents and what the register knows must be the same set, or one lies. */
    public function testTheExampleEnvironmentDocumentsEveryClassifiedKey(): void
    {
        $root = dirname(__DIR__, 2);
        $example = (string) file_get_contents($root . '/.env.example');
        preg_match_all('/^([A-Z0-9_]+)=/m', $example, $matches);
        $documented = $matches[1];

        self::assertNotSame([], $documented, '.env.example documents nothing; the pattern has drifted.');

        $missing = array_values(array_diff($this->register()->classifiedEnvKeys(), $documented));

        self::assertSame(
            [],
            $missing,
            'Classified in the register but absent from .env.example: ' . implode(', ', $missing)
        );

        $unknown = array_values(array_diff($documented, $this->register()->classifiedEnvKeys()));

        self::assertSame(
            [],
            $unknown,
            '.env.example documents keys the register does not classify: ' . implode(', ', $unknown)
        );
    }

    public function testACredentialWithNoRotationOnRecordIsNotTreatedAsCompliant(): void
    {
        $row = $this->row($this->inventory([$this->entry()]), 'DB_PASSWORD', ['DB_PASSWORD']);

        self::assertSame('unrecorded', $row['state']);
        self::assertStringContainsString('no rotation is on record', $row['detail']);
    }

    public function testOneUnrecordedCredentialIsAWarningUntilTheReleaseGateAsks(): void
    {
        $inventory = $this->inventory([$this->entry()]);
        $report = $inventory->report(self::TODAY, ['DB_PASSWORD']);

        self::assertFalse($inventory->hasFailure($report, false));
        self::assertTrue($inventory->hasFailure($report, true));
    }

    public function testARotationInsideTheIntervalIsOkAndCarriesItsDueDate(): void
    {
        $row = $this->row($this->inventory([$this->entry(['rotated_on' => '2026-11-01'])]), 'DB_PASSWORD');

        self::assertSame('ok', $row['state']);
        self::assertSame('2027-01-30', $row['due_on']);
        self::assertSame(67, $row['days_remaining']);
    }

    public function testARotationInsideTheFinalFortnightIsDueRatherThanOk(): void
    {
        // Ninety days from 2026-09-05 is 2026-12-04, which is ten days after the report date and so
        // inside the warning window without being overdue.
        $row = $this->row($this->inventory([$this->entry(['rotated_on' => '2026-09-05'])]), 'DB_PASSWORD');

        self::assertSame('due', $row['state']);
        self::assertSame(10, $row['days_remaining']);
    }

    public function testARotationPastItsIntervalIsAFailure(): void
    {
        $inventory = $this->inventory([$this->entry(['rotated_on' => '2026-08-01'])]);
        $report = $inventory->report(self::TODAY, ['DB_PASSWORD']);

        self::assertSame('overdue', $report[0]['state']);
        self::assertTrue($inventory->hasFailure($report, false));
        self::assertStringContainsString('overdue', $report[0]['detail']);
    }

    public function testARotationDatedInTheFutureIsARegisterErrorRatherThanAFreePass(): void
    {
        $inventory = $this->inventory([$this->entry(['rotated_on' => '2027-01-01'])]);
        $report = $inventory->report(self::TODAY, ['DB_PASSWORD']);

        self::assertSame('invalid', $report[0]['state']);
        self::assertTrue($inventory->hasFailure($report, false));
    }

    public function testAnUnnamedCustodianFailsOnlyTheReleaseGate(): void
    {
        $inventory = $this->inventory([$this->entry(['rotated_on' => '2026-11-01'])]);
        $report = $inventory->report(self::TODAY, ['DB_PASSWORD']);

        self::assertSame('ok', $report[0]['state']);
        self::assertNull($report[0]['custodian']);
        self::assertFalse($inventory->hasFailure($report, false));
        self::assertTrue($inventory->hasFailure($report, true));
    }

    public function testAnExternalCredentialIsUnobservableRatherThanPassingOrFailingOnItsOwn(): void
    {
        $inventory = $this->inventory([
            $this->entry([
                'key' => 'PANEL_PASSWORD',
                'source' => 'external',
                'kind' => 'hosting',
                'office' => 'Operations Lead',
            ]),
        ]);
        $report = $inventory->report(self::TODAY, []);

        // Presence cannot be read from this host, so the row states that instead of inventing a pass.
        self::assertSame('unobservable', $report[0]['state']);

        // It still has an office, and the release gate still asks who holds it: an external secret
        // with nobody named is the S1 gap, not an exemption from it.
        self::assertSame('Operations Lead', $report[0]['office']);
        self::assertFalse($inventory->hasFailure($report, false));
        self::assertTrue($inventory->hasFailure($report, true));
    }

    /**
     * A request is not a rotation.
     *
     * D-10's panel-password finding is an exposure event, and the one thing worse than no record is a
     * record that says it was fixed. The date of the *request* is worth holding — a request with no date
     * is a sentence in a chat log, and this project has already watched a control gap survive on those —
     * but it must not change the state, and `--require-recorded` must still fail on the row.
     */
    public function testARequestedRotationIsRecordedWithoutBeingCountedAsDone(): void
    {
        $inventory = $this->inventory([
            $this->entry([
                'key' => 'PANEL_PASSWORD',
                'source' => 'external',
                'kind' => 'hosting',
                'office' => 'Operations Lead',
                'rotation_requested_on' => '2026-11-27',
            ]),
        ]);
        $report = $inventory->report(self::TODAY, []);

        self::assertSame('unobservable', $report[0]['state']);
        self::assertStringContainsString('rotation requested 2026-11-27', $report[0]['detail']);

        // Requested, not done: the release gate must keep failing the row.
        self::assertTrue($inventory->hasFailure($report, true));
    }

    public function testAnImpossibleRequestDateIsRefusedAtLoad(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([
            $this->entry(['rotation_requested_on' => '2026-02-30']),
        ]);
    }

    public function testTheBackupKeyWithARotationIsGovernedByTheArchiveRule(): void
    {
        $row = $this->row(
            $this->inventory([$this->entry([
                'key' => 'BACKUP_ENCRYPTION_KEY',
                'kind' => 'backup-key',
                'interval_days' => null,
                'rotated_on' => '2026-01-01',
            ])]),
            'BACKUP_ENCRYPTION_KEY'
        );

        self::assertSame('ok', $row['state']);
        self::assertStringContainsString('archive rule', $row['detail']);
    }

    public function testArchivesBeforeTheRotationAreTheOnesTheOldKeyStillUnlocks(): void
    {
        $predating = CredentialInventory::archivesPredating([
            'booking-20261101-010000.sql.gz',
            'booking-20261120-120000.sql.gz.enc',
            'booking-20261124-070000-1.sql.gz.enc',
            '.gitkeep',
            'notes.txt',
        ], '2026-11-20');

        self::assertSame(['booking-20261101-010000.sql.gz'], $predating);
    }

    public function testAnArchiveWrittenOnTheRotationDayIsNotTreatedAsPredating(): void
    {
        self::assertSame(
            [],
            CredentialInventory::archivesPredating(['booking-20261120-000001.sql.gz'], '2026-11-20')
        );
    }

    public function testTheSameSecretCannotBeListedTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([$this->entry(), $this->entry()]);
    }

    public function testASecretAndANonSecretCannotShareAKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([$this->entry()], ['DB_PASSWORD' => 'the database password']);
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([$this->entry(['kind' => 'misc'])]);
    }

    public function testACredentialWithoutAnOfficeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([$this->entry(['office' => '  '])]);
    }

    public function testTheRegisterRefusesADateThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([$this->entry(['rotated_on' => '2026-02-30'])]);
    }

    public function testTheRegisterRefusesAnIntervalNoRotationCouldMeet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory([$this->entry(['interval_days' => 0])]);
    }

    public function testAMissingRegisterFileIsAnErrorRatherThanAnEmptyRegister(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CredentialInventory::fromFile(dirname(__DIR__, 2) . '/database/operations/not-the-register.php');
    }
}
