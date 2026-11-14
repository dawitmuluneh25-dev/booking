<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The activation answer, with the reason it was given.
 *
 * The report is a value so the board, the refusal and the configuration screen
 * cannot disagree: all three read the same assessment rather than each deciding
 * whether a roster is good enough.
 */
final class EscalationReadinessReport
{
    /** @param list<EscalationRouteKind> $missing */
    public function __construct(
        public readonly bool $activated,
        public readonly array $missing,
    ) {
    }

    /**
     * What is missing, in the words the person who has to go and confirm it needs.
     *
     * This sentence is for the clinic, not for a patient. Decision 4 forbids showing
     * an unconfigured coordination capability on a public page, and nothing in this
     * report may reach one.
     */
    public function message(): string
    {
        if ($this->activated) {
            return 'Escalation arrangements are recorded and verified.';
        }

        $names = array_map(
            static fn (EscalationRouteKind $kind): string => strtolower($kind->label()),
            $this->missing,
        );

        return 'Urgent escalation is disabled: no verified ' . $this->join($names)
            . ' is recorded. Operations has to record the arrangement and confirm that it is answered '
            . 'before urgent coordination can be used.';
    }

    /** @param list<string> $items */
    private function join(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' or ' . $last;
    }
}
