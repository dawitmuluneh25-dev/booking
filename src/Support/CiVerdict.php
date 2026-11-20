<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One answer about one commit.
 *
 * It carries the run number and the URL rather than only a colour, because "green"
 * on its own invites the next question — which run, on which commit — and the point
 * of this type is to answer that question without a second trip to the browser.
 */
final readonly class CiVerdict
{
    public function __construct(
        public CiState $state,
        public string $sha,
        public string $workflow,
        public ?int $runNumber = null,
        public ?string $url = null,
        public ?string $completedAt = null,
    ) {
    }

    public function exitCode(): int
    {
        return $this->state->exitCode();
    }

    /**
     * The line a person reads. It never says "green" for a state that is not green,
     * which sounds like it should go without saying and is the defect this tool was
     * written to fix.
     */
    public function describe(): string
    {
        $short = substr($this->sha, 0, 7);

        return match ($this->state) {
            CiState::Green => sprintf(
                'CI: green — run #%d (%s) for %s, completed %s',
                (int) $this->runNumber,
                $this->workflow,
                $short,
                (string) $this->completedAt,
            ),
            CiState::Red => sprintf(
                'CI: RED — run #%d (%s) for %s, completed %s',
                (int) $this->runNumber,
                $this->workflow,
                $short,
                (string) $this->completedAt,
            ),
            CiState::Pending => sprintf(
                'CI: not finished — run #%d (%s) for %s is still running',
                (int) $this->runNumber,
                $this->workflow,
                $short,
            ),
            CiState::Absent => sprintf(
                'CI: no run — no %s run exists for %s',
                $this->workflow,
                $short,
            ),
        };
    }

    /**
     * @return array{
     *     state: string,
     *     sha: string,
     *     workflow: string,
     *     run: int|null,
     *     url: string|null,
     *     completed_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'sha' => $this->sha,
            'workflow' => $this->workflow,
            'run' => $this->runNumber,
            'url' => $this->url,
            'completed_at' => $this->completedAt,
        ];
    }
}
