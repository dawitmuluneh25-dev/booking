<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Add the next laboratory to the series (§19.1).
 *
 * The serial is assigned from the catalogue rather than typed in, because a typed
 * serial is a typed identifier: two people can enter "3", or skip from 2 to 7 and
 * leave a gap nobody can explain. The approved decision named a serialised scheme,
 * and this is the smallest thing that keeps it serialised.
 *
 * The command deliberately takes no name. §19.1 requires the destination to be a
 * configured reference; the decision that configured it named the scheme and left
 * real laboratory names to be supplied, so accepting one here would be inventing
 * the fact that a free-text box is allowed to become the record.
 */
final class AddLaboratoryDestination
{
    /**
     * The CHECK in migration 0031 enforces the same ceiling. This half of the rule
     * exists so a user gets a sentence rather than a driver exception.
     */
    public const MAX_SERIAL = 999;

    public function __construct(private readonly LaboratoryDestinationRepository $destinations)
    {
    }

    /**
     * @throws AuthorisationException the actor may not configure the clinic
     * @throws ValidationException    the series is exhausted
     */
    public function execute(Actor $actor, ?string $nowUtc = null): LaboratoryDestination
    {
        if (!$actor->can(Permission::ClinicConfigure)) {
            throw new AuthorisationException($actor->role, Permission::ClinicConfigure);
        }

        $next = $this->destinations->nextSerial();

        if ($next > self::MAX_SERIAL) {
            throw new ValidationException([
                'serial_no' => 'The laboratory series is full. A real naming scheme has to '
                    . 'replace the serial before another laboratory can be added.',
            ]);
        }

        return $this->destinations->create($next, $nowUtc ?? gmdate('Y-m-d H:i:s'));
    }
}
