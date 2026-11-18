<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A stranger asks for a referring account (ADR-013, owner decision 18 Nov 2026).
 *
 * The command is written around one constraint: an unauthenticated caller must learn
 * nothing. Every branch that could distinguish "this address already has an account"
 * from "this address is new" ends in the same page with a reference number, and a
 * submission that is refused for abuse gets the same treatment unless it is refused
 * for the network's allowance, which says nothing about anybody's account.
 *
 * Three controls, in the order they run:
 *
 *   1. the per-network cap, because a bot must not be able to fill the table even
 *      with junk that fails validation;
 *   2. the honeypot, which returns a believable reference and writes nothing — a
 *      bot that is told "invalid" learns which field to change;
 *   3. the challenge question, then field validation, then duplicate collapse.
 *
 * The reference is generated here rather than by the database because the form must
 * be able to show it before anything is committed — including on the honeypot path,
 * where nothing is.
 */
final class RequestAccount
{
    /** No O/0/I/1/S/5/B/8/Z/2: a reference is read aloud over a telephone. */
    private const ALPHABET = 'ACDEFGHJKLMNPQRTUVWXY34679';

    private const REFERENCE_PREFIX = 'AR-';

    private const REFERENCE_LENGTH = 7;

    private const DUPLICATE_WINDOW_HOURS = 24;

    public function __construct(
        private readonly AccountRequestRepository $requests,
        private readonly AccountRequestThrottle $throttle,
        private readonly HumanCheckVerifier $humanCheck,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input posted form fields
     *
     * @return string the reference to show, whether or not a row was written
     *
     * @throws AccountRequestThrottledException the network has used its allowance
     * @throws ValidationException               the details are incomplete
     */
    public function execute(array $input, string $ipHash, ?string $nowUtc = null): string
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->throttle->admit($ipHash, $now)) {
            throw new AccountRequestThrottledException(60);
        }

        // A field no human sees and no browser autofills. Filled means a script.
        if (trim($this->text($input, 'website')) !== '') {
            return $this->reference();
        }

        if (!$this->humanCheck->verify($this->text($input, 'check_token'), $this->text($input, 'check_answer'))) {
            throw new ValidationException(['check_answer' => 'Answer the question to continue.']);
        }

        $fullName = trim($this->text($input, 'full_name'));
        $email = mb_strtolower(trim($this->text($input, 'email')));
        $practiceName = trim($this->text($input, 'practice_name'));
        $practiceCity = trim($this->text($input, 'practice_city'));
        $registrationNo = trim($this->text($input, 'registration_no'));
        $note = trim($this->text($input, 'note'));

        $errors = [];

        if (mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'Enter the full name of the person requesting access.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if (mb_strlen($practiceName) < 2) {
            $errors['practice_name'] = 'Enter the practice or institution name.';
        }

        if (mb_strlen($practiceCity) < 2) {
            $errors['practice_city'] = 'Enter the city the practice is in.';
        }

        if (mb_strlen($registrationNo) > 60) {
            $errors['registration_no'] = 'Keep the registration number under 60 characters.';
        }

        if (mb_strlen($note) > 1000) {
            $errors['note'] = 'Keep this under 1000 characters.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // The same person pressing submit twice is one request, not two. Answering
        // with the first reference also means the second press does not look like a
        // failure to the person who made it.
        $since = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - (self::DUPLICATE_WINDOW_HOURS * 3600));
        $duplicate = $this->requests->findRecentDuplicate($email, $practiceName, $since);

        if ($duplicate !== null) {
            return $duplicate->reference;
        }

        $request = new AccountRequest(
            id: 0,
            reference: $this->unusedReference(),
            fullName: $fullName,
            email: $email,
            practiceName: $practiceName,
            practiceCity: $practiceCity,
            registrationNo: $registrationNo === '' ? null : $registrationNo,
            note: $note === '' ? null : $note,
            status: AccountRequestStatus::Pending,
            createdAtUtc: $now,
        );

        $stored = $this->requests->create($request, $now);

        // No actor: this is an unauthenticated submission, and inventing an identity
        // for the trail would make the row a lie. The reference is recorded; the
        // stranger's free-text note and address are not, because the trail is read by
        // administrators and the request row already holds them.
        $this->audit->record(new AuditEntry(
            action: AuditAction::AccountRequested,
            objectType: 'account_request',
            objectRef: (string) $stored->id,
            after: ['reference' => $stored->reference, 'status' => AccountRequestStatus::Pending->value],
            occurredAtUtc: $now,
        ));

        return $stored->reference;
    }

    private function unusedReference(): string
    {
        // Bounded rather than a loop: a collision is a 1-in-10^10 event, and a
        // caller waiting on a retry forever would be worse than a clear failure.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->reference();

            if (!$this->requests->referenceExists($candidate)) {
                return $candidate;
            }
        }

        return $this->reference();
    }

    private function reference(): string
    {
        $reference = self::REFERENCE_PREFIX;
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
            $reference .= self::ALPHABET[random_int(0, $max)];
        }

        return $reference;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
