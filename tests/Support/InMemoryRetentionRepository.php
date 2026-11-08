<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\BookingStatus;
use App\Domain\RetentionRepository;
use App\Domain\RetentionRun;

final class InMemoryRetentionRepository implements RetentionRepository
{
    /** @var list<array{id: int, status: string, scheduled_at: string, redacted: bool}> */
    private array $bookings;

    /** @var list<array{cutoff: string, redacted: int, actor: string}> */
    private array $runs = [];

    /** @param list<array{id: int, status: string, scheduled_at: string, redacted?: bool}> $bookings */
    public function __construct(array $bookings = [])
    {
        $this->bookings = [];
        foreach ($bookings as $booking) {
            $this->bookings[] = [
                'id' => $booking['id'],
                'status' => $booking['status'],
                'scheduled_at' => $booking['scheduled_at'],
                'redacted' => $booking['redacted'] ?? false,
            ];
        }
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        $count = 0;
        foreach ($this->bookings as $booking) {
            if ($this->eligible($booking, $cutoffUtc)) {
                $count++;
            }
        }

        return $count;
    }

    public function redactBefore(string $cutoffUtc, int $limit): int
    {
        $changed = 0;

        foreach ($this->bookings as $index => $booking) {
            if ($changed >= $limit) {
                break;
            }

            if (!$this->eligible($booking, $cutoffUtc)) {
                continue;
            }

            $this->bookings[$index]['redacted'] = true;
            $changed++;
        }

        return $changed;
    }

    public function recordRun(string $cutoffDate, int $redacted, string $actor): void
    {
        $this->runs[] = ['cutoff' => $cutoffDate, 'redacted' => $redacted, 'actor' => $actor];
    }

    /** @return list<RetentionRun> */
    public function recentRuns(int $limit): array
    {
        $runs = [];
        foreach (array_reverse($this->runs) as $run) {
            $runs[] = new RetentionRun($run['cutoff'], $run['redacted'], $run['actor'], '2026-01-01 00:00:00');
        }

        return array_slice($runs, 0, $limit);
    }

    /** @return list<array{cutoff: string, redacted: int, actor: string}> */
    public function runs(): array
    {
        return $this->runs;
    }

    /** @param array{id: int, status: string, scheduled_at: string, redacted: bool} $booking */
    private function eligible(array $booking, string $cutoffUtc): bool
    {
        $status = BookingStatus::tryFrom($booking['status']);

        return !$booking['redacted']
            && $status !== null
            && $status->isTerminal()
            && $booking['scheduled_at'] < $cutoffUtc;
    }
}
