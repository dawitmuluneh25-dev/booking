<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where a piece of patient-facing content stands (§17.5).
 *
 * There are three states and no fourth. "Expired" is deliberately absent: a page
 * expires because its review date has passed, which is a fact about the calendar
 * rather than an act anybody performed, so an 'expired' status would have to be
 * written by a job at midnight — and a job that writes a status is a job that can
 * fail to run while the page stays up.
 */
enum ContentStatus: string
{
    /** Written, not shown. Every §17.5 field may be incomplete here. */
    case Draft = 'draft';

    /** Live. The schema refuses this state unless §17.5 is complete. */
    case Published = 'published';

    /** Withdrawn from display, kept so the record of what was shown survives. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
