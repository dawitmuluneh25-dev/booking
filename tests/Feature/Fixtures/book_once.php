<?php

declare(strict_types=1);

/**
 * One contender in the reservation race.
 *
 * Run as a separate process by `ReservationConcurrencyTest` so the requests are
 * genuinely concurrent: separate connections, separate transactions, no shared
 * PHP memory. The script waits for a wall-clock barrier, then takes the resource
 * lock and attempts the booking in the same order `BookAppointment` does.
 *
 * Arguments arrive in a JSON file rather than on the command line because a DSN,
 * a password and a UTC timestamp through Windows quoting is three ways to test
 * the shell instead of the database.
 */

use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\Role;
use App\Domain\SlotUnavailableException;
use App\Repositories\PdoAppointmentRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * @param array<string, mixed> $arguments
 */
function text(array $arguments, string $key): string
{
    $value = $arguments[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * @param array<string, mixed> $arguments
 */
function number(array $arguments, string $key): int
{
    $value = $arguments[$key] ?? 0;

    return is_numeric($value) ? (int) $value : 0;
}

$raw = file_get_contents((string) ($argv[1] ?? ''));

if ($raw === false) {
    exit(1);
}

$decoded = json_decode($raw, true);

if (!is_array($decoded)) {
    exit(1);
}

/** @var array<string, mixed> $arguments */
$arguments = $decoded;

$pdo = new PDO(
    text($arguments, 'dsn'),
    text($arguments, 'user'),
    text($arguments, 'password'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

$repository = new PdoAppointmentRepository($pdo, new PdoTransaction($pdo));
$actor = new Actor(number($arguments, 'actor_id'), Role::Staff);

$startAt = is_numeric($arguments['start_at'] ?? null) ? (float) $arguments['start_at'] : 0.0;

while (microtime(true) < $startAt) {
    usleep(200);
}

$result = ['ok' => false, 'reason' => 'not attempted'];

try {
    // The same order as the command: take the resource row first, then write the
    // occupancy rows.
    $repository->lockResource(number($arguments, 'resource_id'));

    $repository->book(
        new Appointment(
            id: 0,
            reference: text($arguments, 'reference'),
            referralId: number($arguments, 'referral_id'),
            status: AppointmentStatus::Scheduled,
            version: 1,
            startsAtUtc: text($arguments, 'slot'),
            endsAtUtc: text($arguments, 'slot_end'),
            durationMinutes: 15,
            patientName: 'Concurrency Fixture',
            patientPhone: '+251911000000',
            createdByUserId: number($arguments, 'actor_id'),
            createdAtUtc: gmdate('Y-m-d H:i:s'),
        ),
        number($arguments, 'resource_id'),
        [text($arguments, 'slot')],
        $actor,
    );

    $result = ['ok' => true, 'reason' => ''];
} catch (SlotUnavailableException) {
    $result = ['ok' => false, 'reason' => 'slot'];
} catch (Throwable $exception) {
    $result = ['ok' => false, 'reason' => $exception::class . ': ' . $exception->getMessage()];
}

file_put_contents(text($arguments, 'out'), json_encode($result));
