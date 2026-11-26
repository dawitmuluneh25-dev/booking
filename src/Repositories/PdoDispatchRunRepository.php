<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\DispatchRun;
use App\Domain\DispatchRunRepository;
use PDO;

final class PdoDispatchRunRepository implements DispatchRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(DispatchRun $run): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO dispatch_runs (started_at, finished_at, claimed, sent, retrying, dead, host)'
            . ' VALUES (:started_at, :finished_at, :claimed, :sent, :retrying, :dead, :host)',
        );

        $statement->execute([
            'started_at' => $run->startedAtUtc,
            'finished_at' => $run->finishedAtUtc,
            'claimed' => $run->claimed,
            'sent' => $run->sent,
            'retrying' => $run->retrying,
            'dead' => $run->dead,
            'host' => mb_substr($run->host, 0, 120),
        ]);
    }

    public function lastRun(): ?DispatchRun
    {
        $statement = $this->pdo->query(
            'SELECT started_at, finished_at, claimed, sent, retrying, dead, host'
            . ' FROM dispatch_runs ORDER BY started_at DESC, id DESC LIMIT 1',
        );

        if ($statement === false) {
            return null;
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? DispatchRun::fromRow($row) : null;
    }
}
