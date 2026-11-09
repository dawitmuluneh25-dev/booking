<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ATDS 22.5, as a measurement rather than a claim: twenty clients, one slot.
 *
 * The contenders are real PHP processes with their own database connections,
 * released by a wall-clock barrier. That matters — twenty calls in one process
 * would prove only that the second insert fails after the first commits, not that
 * two transactions racing for the same row resolve to one winner.
 *
 * The expected outcome is exactly one success and nineteen refusals that are all
 * the same refusal. A test that accepted "one success, nineteen assorted errors"
 * would hide a deadlock behind a passing assertion.
 */
#[Group('concurrency')]
final class ReservationConcurrencyTest extends TestCase
{
    private const CONTENDERS = 20;

    /** A Tuesday far enough away that no real booking can be sitting on it. */
    private const SLOT = '2033-01-04 09:00:00';

    private const SLOT_END = '2033-01-04 09:15:00';

    private ?PDO $pdo = null;

    private int $actorUserId = 0;

    private int $referralId = 0;

    private string $workDirectory = '';

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled on this host.');
        }

        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');

        try {
            $this->pdo = Connection::create((new Config($root))->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->actorUserId = $this->insertActor();
        $this->referralId = $this->insertReferral();
        $this->workDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'reservation-race-' . bin2hex(random_bytes(6));

        if (!mkdir($this->workDirectory, 0770, true) && !is_dir($this->workDirectory)) {
            self::fail('Cannot create the working directory.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            // `resource_slots` and `appointment_resources` cascade from the
            // appointment, so one delete clears the race completely.
            $this->pdo->exec(
                "DELETE FROM appointment_status_history
                  WHERE appointment_id IN (SELECT id FROM appointments WHERE reference LIKE 'BK-CONC-%')"
            );
            $this->pdo->exec("DELETE FROM appointments WHERE reference LIKE 'BK-CONC-%'");
            $this->pdo->exec("DELETE FROM referrals WHERE reference LIKE 'RF-CONC-%'");

            if ($this->actorUserId > 0) {
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
            }
        }

        $this->removeTree($this->workDirectory);

        $this->pdo = null;
        $this->actorUserId = 0;
        $this->workDirectory = '';
    }

    public function testTwentyConcurrentClientsProduceExactlyOneBooking(): void
    {
        $resourceId = $this->resourceId();
        $config = (new Config(dirname(__DIR__, 2)))->database();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        $startAt = microtime(true) + 3.0;
        $processes = [];
        $outputs = [];

        for ($index = 1; $index <= self::CONTENDERS; $index++) {
            $id = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $arguments = $this->workDirectory . DIRECTORY_SEPARATOR . 'args-' . $id . '.json';
            $out = $this->workDirectory . DIRECTORY_SEPARATOR . 'result-' . $id . '.json';

            file_put_contents($arguments, (string) json_encode([
                'dsn' => $dsn,
                'user' => $config['username'],
                'password' => $config['password'],
                'resource_id' => $resourceId,
                'slot' => self::SLOT,
                'slot_end' => self::SLOT_END,
                'actor_id' => $this->actorUserId,
                'reference' => 'BK-CONC-' . $id,
                'referral_id' => $this->referralId,
                'start_at' => $startAt,
                'out' => $out,
            ], JSON_THROW_ON_ERROR));

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $command = [PHP_BINARY, __DIR__ . '/Fixtures/book_once.php', $arguments];

            $process = proc_open($command, $descriptors, $pipes);

            if (!is_resource($process)) {
                self::fail('Could not start contender ' . $id . '.');
            }

            $processes[$id] = ['process' => $process, 'pipes' => $pipes, 'out' => $out];
        }

        foreach ($processes as $id => $entry) {
            $stdout = (string) stream_get_contents($entry['pipes'][1]);
            $stderr = (string) stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);

            $exit = proc_close($entry['process']);

            $raw = is_file($entry['out']) ? (string) file_get_contents($entry['out']) : '';
            $decoded = $raw === '' ? null : json_decode($raw, true);

            if (!is_array($decoded)) {
                self::fail(sprintf(
                    'Contender %s produced no verdict (exit %d). stdout: %s stderr: %s',
                    (string) $id,
                    $exit,
                    trim($stdout),
                    trim($stderr),
                ));
            }

            $outputs[(string) $id] = $decoded;
        }

        $winners = array_filter($outputs, static fn (array $r): bool => ($r['ok'] ?? false) === true);
        $refusals = array_filter($outputs, static fn (array $r): bool => ($r['reason'] ?? '') === 'slot');
        $others = array_filter(
            $outputs,
            static fn (array $r): bool => ($r['ok'] ?? false) !== true && ($r['reason'] ?? '') !== 'slot',
        );

        self::assertCount(1, $winners, 'Expected exactly one booking to win the slot.');
        self::assertCount(self::CONTENDERS - 1, $refusals, 'Every loser must be refused for the same reason.');
        self::assertSame([], $others, 'A contender failed for a reason other than the slot being taken.');
    }

    private function insertReferral(): int
    {
        $statement = $this->pdo()->prepare(
            "INSERT INTO referrals
                (reference, status, patient_name, patient_phone, procedure_code,
                 submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES
                ('RF-CONC-01', 'converted_to_appointment', 'Race Fixture', '+251911000000',
                 'fluoroscopic_biopsy', :user_id, 'referrer', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute(['user_id' => $this->actorUserId]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertActor(): int
    {
        $email = 'race-' . bin2hex(random_bytes(4)) . '@example.test';

        $statement = $this->pdo()->prepare(
            "INSERT INTO users (email, name, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:email, 'Race Fixture', :hash, 'staff', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([
            'email' => $email,
            'hash' => password_hash('race-fixture-not-a-login', PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function resourceId(): int
    {
        $statement = $this->pdo()->query("SELECT id FROM resources WHERE code = 'suite-1' LIMIT 1");
        $value = $statement === false ? null : $statement->fetchColumn();

        if (!is_numeric($value)) {
            self::markTestSkipped('The seeded suite-1 resource is missing.');
        }

        return (int) $value;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection.');
        }

        return $this->pdo;
    }

    private function removeTree(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            @unlink($directory . DIRECTORY_SEPARATOR . (string) $item);
        }

        @rmdir($directory);
    }
}
