<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Permission;
use App\Domain\PhysicianProfile;
use App\Domain\PhysicianStatus;
use App\Domain\Referral;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralRepository;
use App\Domain\ReferralStatus;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a referring physician has sent us, and what we are waiting for.
 *
 * The list is scoped by `referrals.referring_physician_profile_id`, not by who
 * typed the form: a referral entered by reception on a physician's behalf is still
 * that physician's referral, and one entered by a colleague is not. The profile is
 * resolved through `ReferralAccessResolver`, so the "only a verified referrer reads
 * a referral" rule (AUTH-02/AUTH-03) has one home rather than a second copy here.
 *
 * What the page deliberately does not do is open `/referrals/{reference}`. That
 * page is the clinic's working record — pre-procedure assessment, consent, priority
 * history, the radiologist's rationale — and no decision has been taken about how
 * much of it a referrer may read. Showing the state of a request and what is
 * outstanding is the deliverable; disclosing the clinical record is a separate
 * decision this controller must not make by accident.
 */
final class MyReferralsController
{
    private const PATH = '/my-referrals';

    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly ReferralRepository $referrals,
        private readonly ReferralAccessResolver $access,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::PATH));
        }

        $this->gate->authorize(Permission::ReferralReadOwn);

        $user = $this->gate->authenticate();
        $profile = $this->access->profileFor($user);

        // No profile, or one that is not verified: there is no set of referrals to
        // read. Saying why beats an empty table that reads as "you have sent us
        // nothing" — the two are different facts and only one of them is true.
        if ($profile === null || !$profile->mayRefer()) {
            return $this->page([], $this->withheldReason($profile));
        }

        $rows = [];

        foreach ($this->referrals->referredBy($profile->id, self::PAGE_SIZE) as $referral) {
            $rows[] = [
                'reference' => $referral->reference,
                'patient' => $referral->patientName,
                'procedure' => $referral->procedureCode,
                'state' => $referral->status->label(),
                'sent' => $this->localTime($referral->createdAtUtc),
                'outstanding' => $this->outstanding($referral),
            ];
        }

        return $this->page($rows, null);
    }

    /**
     * Why nothing is listed, when nothing may be. Null means "there is a set to
     * show", which is the only case in which the page renders the table.
     */
    private function withheldReason(?PhysicianProfile $profile): string
    {
        if ($profile === null) {
            return 'This account is not linked to a referrer record, so no referrals are listed.';
        }

        return match ($profile->status) {
            PhysicianStatus::Pending => 'Your referrer record is ' . mb_strtolower($profile->status->label())
                . '. Referrals appear here once it is verified.',
            PhysicianStatus::Rejected => 'Your referrer record was ' . mb_strtolower($profile->status->label())
                . ', so no referrals are listed.',
            PhysicianStatus::Suspended => 'Your referrer record is ' . mb_strtolower($profile->status->label())
                . ', so no referrals are listed.',
            // Verified profiles never reach this method: `mayRefer()` is true and
            // the caller lists them instead.
            PhysicianStatus::Verified => 'No referrals are listed.',
        };
    }

    /**
     * What the service is waiting for on this referral, if anything.
     *
     * Only `awaiting_information` has something outstanding, and the text has two
     * possible authors: reception asking for a missing document, recorded on the
     * transition, and a radiologist asking a clinical question, recorded as the
     * decision's `information_requested`. The repository knows which is newest; the
     * page prints whichever it returns and never guesses one.
     */
    private function outstanding(Referral $referral): ?string
    {
        if ($referral->status !== ReferralStatus::AwaitingInformation) {
            return null;
        }

        return $this->referrals->outstandingInformation($referral->id);
    }

    private function localTime(string $utc): string
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($at === false) {
            return $utc;
        }

        return $at->setTimezone(new DateTimeZone($this->timezone))->format('D j M Y, H:i');
    }

    /**
     * @param list<array{
     *     reference: string,
     *     patient: string,
     *     procedure: string,
     *     state: string,
     *     sent: string,
     *     outstanding: ?string
     * }> $referrals
     */
    private function page(array $referrals, ?string $withheld): Response
    {
        return Response::html($this->view->render('referrals/mine', [
            'title' => 'My referrals',
            'referrals' => $referrals,
            'withheld' => $withheld,
            'currentUser' => $this->gate->authenticate(),
        ]));
    }
}
