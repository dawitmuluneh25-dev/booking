<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\OperationalAppointment;
use App\Domain\Permission;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * D-07's five operational appointments, and the boundary that keeps them from becoming access.
 *
 * The decision adopts five responsibilities and then constrains them in two sentences that are easy
 * to satisfy on paper and easy to violate in code: "Do not create fictitious employee identities"
 * and "Do not infer clinical privileges from an operational job title."
 *
 * A test cannot prove the absence of a fictitious person. It can prove the two things that would
 * make one possible: a title that is also a login role, and an appointment that hands out a
 * permission. Both are asserted here, structurally, so that the cheap way to wire an operating model
 * into the access layer — give the coordinator a permission because the coordinator needs it — fails
 * the build instead of shipping.
 */
final class OperationalAppointmentTest extends TestCase
{
    /** D-07's list, in its order. */
    private const TITLES = [
        'Operations Lead',
        'Referral and Intake Coordinator',
        'Clinical Coordination Officer',
        'Procedure Scheduling and Resource Coordinator',
        'Backup and Escalation Duty Coordinator',
    ];

    public function testTheFiveAppointmentsAreTheFiveTheDecisionAdopts(): void
    {
        $titles = array_map(
            static fn (OperationalAppointment $appointment): string => $appointment->title(),
            OperationalAppointment::cases(),
        );

        self::assertSame(self::TITLES, $titles);
    }

    public function testEveryAppointmentStatesWhatItOwns(): void
    {
        foreach (OperationalAppointment::cases() as $appointment) {
            self::assertNotSame('', trim($appointment->responsibility()));
            self::assertGreaterThan(20, strlen($appointment->responsibility()));
        }
    }

    /**
     * The Clinical Coordination Officer coordinates and does not decide.
     *
     * This is the one appointment whose D-07 wording could be read as clinical authority — it is the
     * one that sits between referring teams and clinicians — and the decision closes that reading
     * explicitly. Asserted on its own so the sentence is not lost among five.
     */
    public function testTheClinicalCoordinationOfficerDoesNotExerciseClinicalAuthority(): void
    {
        $officer = OperationalAppointment::ClinicalCoordinationOfficer;

        self::assertFalse($officer->confersClinicalAuthority());
        self::assertStringContainsString('without independently exercising clinical decision authority', $officer->responsibility());

        foreach (OperationalAppointment::cases() as $appointment) {
            self::assertFalse(
                $appointment->confersClinicalAuthority(),
                $appointment->title() . ' was given clinical authority by a job title.',
            );
        }
    }

    /**
     * No appointment is a login role, and none of the five titles is a `Role` case.
     *
     * Access in this application is decided by `Actor::can()`, which consults `Role`. An appointment
     * that also existed as a role would make "who does this job" and "what may this login do" the
     * same question, which is exactly the inference D-07 forbids.
     */
    public function testNoAppointmentIsAlsoAnAccessRole(): void
    {
        $roleValues = array_map(static fn (Role $role): string => $role->value, Role::cases());
        $roleNames = array_map(static fn (Role $role): string => $role->name, Role::cases());

        foreach (OperationalAppointment::cases() as $appointment) {
            self::assertNotContains($appointment->value, $roleValues);
            self::assertNotContains($appointment->name, $roleNames);
        }
    }

    /**
     * The enum cannot hand out a permission, because it has no method that returns one.
     *
     * This is the structural half of D-07's second constraint. A future change that added
     * `permissions(): array` here would pass every other test in this file and quietly make a job
     * title into a grant; this one fails instead.
     */
    public function testTheEnumHasNoWayToGrantAPermission(): void
    {
        $reflection = new ReflectionClass(OperationalAppointment::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($returnType === null) {
                continue;
            }

            self::assertStringNotContainsString(
                Permission::class,
                (string) $returnType,
                $method->getName() . '() returns a Permission, so a job title can now grant access.',
            );
            self::assertStringNotContainsString(
                'Permission',
                (string) $returnType,
                $method->getName() . '() returns something permission-shaped.',
            );
        }
    }
}
