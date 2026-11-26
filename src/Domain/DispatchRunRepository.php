<?php

declare(strict_types=1);

namespace App\Domain;

interface DispatchRunRepository
{
    public function record(DispatchRun $run): void;

    /**
     * The most recently *completed* run, or null when the schedule has never run.
     *
     * Null is a fact here, not a missing value: it is the difference between "the
     * queue is empty" and "nothing has ever drained it".
     */
    public function lastRun(): ?DispatchRun;
}
