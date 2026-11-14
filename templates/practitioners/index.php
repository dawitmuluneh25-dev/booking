<?php
/**
 * @var list<array{
 *     user: \App\Domain\User,
 *     credentials: list<array{
 *         credential: \App\Domain\PractitionerCredential,
 *         verifiedBy: string,
 *         verifiedAt: string,
 *         privileges: list<array{
 *             privilege: \App\Domain\PractitionerPrivilege,
 *             delegatedFrom: string|null
 *         }>
 *     }>
 * }> $practitioners
 * @var list<\App\Domain\Procedure>          $procedures
 * @var list<\App\Domain\User>               $accounts
 * @var array<string, string>                $errors
 * @var string|null                          $notice
 * @var string                               $today
 * @var \App\Domain\User                     $currentUser
 */
$eligible = array_values(array_filter(
    $accounts,
    static fn (\App\Domain\User $account): bool => $account->role->can(\App\Domain\Permission::ClinicalApprove),
));
?>
<section class="panel">
    <div class="panel-head">
        <h1>Practitioner credentials</h1>
        <a class="button button-secondary button-small" href="/review">Review queue</a>
    </div>

    <?php if ($notice !== null): ?>
        <p class="alert alert-info"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That change was not recorded.</p>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><a href="#<?= e((string) $field) ?>"><?= e($message) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="lede">
        What a clinical approval rests on. A practitioner may approve a procedure only
        while a recorded verification is in force <em>and</em> names that procedure; the
        review screen refuses the rest and says which part is missing.
    </p>

    <p class="muted">
        Recording a verification is a clinical-governance act, so this screen is not open
        to the administrator account. A title, a rota line or an account role activates
        nothing by itself. Suspension is reversible and revocation is not: a practitioner
        whose credential is revoked is credentialed again by a new record with its own
        evidence, not by reinstating the old one.
    </p>

    <?php if ($eligible === []): ?>
        <p><strong>No account holds clinical approval authority.</strong> A credential
            authorises nothing on an account that cannot approve, so there is nothing to
            record until a clinical account exists.</p>
    <?php endif; ?>

    <?php foreach ($practitioners as $practitioner): ?>
        <?php $account = $practitioner['user']; ?>
        <h2 class="section-title"><?= e($account->name) ?></h2>
        <p class="muted">
            <?= e($account->email) ?> &middot; <?= e($account->role->label()) ?> &middot;
            account #<?= e((string) $account->id) ?>
        </p>

        <?php if ($practitioner['credentials'] === []): ?>
            <p>
                <strong>No verification is recorded.</strong> Approval is unavailable to this
                account until one is, which is the rule AC-009 asks for rather than a fault
                in the account.
            </p>
        <?php else: ?>
            <?php foreach ($practitioner['credentials'] as $entry): ?>
                <?php $credential = $entry['credential']; ?>
                <div class="table-wrap">
                    <table>
                        <caption class="visually-hidden">
                            Recorded credential for <?= e($account->name) ?>
                        </caption>
                        <tbody>
                            <tr>
                                <th scope="row">Credential</th>
                                <td>
                                    <?= e($credential->credentialType) ?>
                                    <span class="muted block mono"><?= e($credential->registrationNumber) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">State</th>
                                <td>
                                    <span class="badge"><?= e($credential->status->label()) ?></span>
                                    <?php if ($credential->expiresOnDay() === null): ?>
                                        <span class="muted block">No expiry recorded.</span>
                                    <?php elseif ($credential->expiresOnDay() < $today): ?>
                                        <span class="muted block">
                                            Expired on <?= e((string) $credential->expiresOnDay()) ?> —
                                            approval is refused from that day.
                                        </span>
                                    <?php else: ?>
                                        <span class="muted block">
                                            Current until <?= e((string) $credential->expiresOnDay()) ?>.
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">In force</th>
                                <td>
                                    From <?= e($credential->startsOn()) ?>
                                    <?= $credential->expiresOnDay() === null
                                        ? '(open-ended)'
                                        : 'to ' . e((string) $credential->expiresOnDay()) ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Evidence</th>
                                <td><?= e($credential->evidenceReference) ?></td>
                            </tr>
                            <?php if ($credential->qualifications !== null): ?>
                                <tr>
                                    <th scope="row">Qualifications</th>
                                    <td><?= nl2br(e($credential->qualifications)) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($credential->institutionalPrivilegeStatus !== null): ?>
                                <tr>
                                    <th scope="row">Institutional status</th>
                                    <td><?= e($credential->institutionalPrivilegeStatus) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($credential->supervisionRestriction !== null): ?>
                                <tr>
                                    <th scope="row">Supervision</th>
                                    <td>
                                        <?= nl2br(e($credential->supervisionRestriction)) ?>
                                        <span class="muted block">
                                            Recorded, not interpreted: the system does not decide what
                                            a supervision condition permits.
                                        </span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row">Verified</th>
                                <td>
                                    By <?= e($entry['verifiedBy']) ?> on <?= e($entry['verifiedAt']) ?>
                                    <?php if ($credential->verifiedByUserId === $account->id): ?>
                                        <span class="badge">Self-recorded</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3 class="section-title">Procedure privileges</h3>
                <?php if ($entry['privileges'] === []): ?>
                    <p>No procedure privilege is recorded under this credential, so it
                        authorises no approval yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <caption class="visually-hidden">Procedure privileges</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Procedure</th>
                                    <th scope="col">Held as</th>
                                    <th scope="col">Window</th>
                                    <th scope="col">Ends</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entry['privileges'] as $row): ?>
                                    <?php $privilege = $row['privilege']; ?>
                                    <tr>
                                        <th scope="row" class="mono"><?= e($privilege->procedureCode) ?></th>
                                        <td>
                                            <?= e($privilege->basis->label()) ?>
                                            <?php if ($row['delegatedFrom'] !== null): ?>
                                                <span class="muted block">
                                                    From <?= e((string) $row['delegatedFrom']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($privilege->supervision !== null): ?>
                                                <span class="muted block"><?= e($privilege->supervision) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= e($privilege->startsOn()) ?> &rarr;
                                            <?= $privilege->endsOn() === null ? 'open' : e((string) $privilege->endsOn()) ?>
                                        </td>
                                        <td>
                                            <?php if ($privilege->endsOn() === null): ?>
                                                <form method="post"
                                                      action="/practitioners/privileges/<?= e((string) $privilege->id) ?>/end"
                                                      class="inline-form">
                                                    <?= \App\Security\Csrf::field() ?>
                                                    <label class="visually-hidden" for="ended_on_<?= e((string) $privilege->id) ?>">
                                                        Day this privilege ends
                                                    </label>
                                                    <input type="date" id="ended_on_<?= e((string) $privilege->id) ?>"
                                                           name="ended_on" value="<?= e($today) ?>" required>
                                                    <button class="button button-secondary button-small" type="submit">
                                                        End
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">Ended</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($credential->status === \App\Domain\PractitionerCredentialStatus::Verified): ?>
                    <details>
                        <summary>Grant a procedure privilege under this credential</summary>
                        <form method="post"
                              action="/practitioners/credentials/<?= e((string) $credential->id) ?>/privileges"
                              class="stack">
                            <?= \App\Security\Csrf::field() ?>

                            <div class="field">
                                <label for="procedure_<?= e((string) $credential->id) ?>">Procedure</label>
                                <select id="procedure_<?= e((string) $credential->id) ?>" name="procedure_code" required>
                                    <?php foreach ($procedures as $procedure): ?>
                                        <option value="<?= e($procedure->code) ?>"><?= e($procedure->label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="basis_<?= e((string) $credential->id) ?>">Held as</label>
                                <select id="basis_<?= e((string) $credential->id) ?>" name="basis">
                                    <?php foreach (\App\Domain\PractitionerPrivilegeBasis::cases() as $basis): ?>
                                        <option value="<?= e($basis->value) ?>"><?= e($basis->label()) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="delegator_<?= e((string) $credential->id) ?>">
                                    Whose privilege is lent (delegation or temporary coverage only)
                                </label>
                                <select id="delegator_<?= e((string) $credential->id) ?>" name="delegated_from_user_id">
                                    <option value="">— none —</option>
                                    <?php foreach ($eligible as $candidate): ?>
                                        <option value="<?= e((string) $candidate->id) ?>"><?= e($candidate->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="start_<?= e((string) $credential->id) ?>">Starts</label>
                                <input type="date" id="start_<?= e((string) $credential->id) ?>"
                                       name="effective_from" value="<?= e($today) ?>" required>
                            </div>

                            <div class="field">
                                <label for="end_<?= e((string) $credential->id) ?>">
                                    Ends (required for temporary coverage)
                                </label>
                                <input type="date" id="end_<?= e((string) $credential->id) ?>" name="effective_to">
                            </div>

                            <div class="field">
                                <label for="supervision_<?= e((string) $credential->id) ?>">
                                    Supervision condition, if any
                                </label>
                                <input type="text" id="supervision_<?= e((string) $credential->id) ?>"
                                       name="supervision" maxlength="300">
                            </div>

                            <div class="field">
                                <label for="note_<?= e((string) $credential->id) ?>">Note</label>
                                <input type="text" id="note_<?= e((string) $credential->id) ?>"
                                       name="note" maxlength="500">
                            </div>

                            <div class="form-actions">
                                <button class="button" type="submit">Record the privilege</button>
                            </div>
                        </form>
                    </details>

                    <form method="post"
                          action="/practitioners/credentials/<?= e((string) $credential->id) ?>/status"
                          class="inline-form">
                        <?= \App\Security\Csrf::field() ?>
                        <input type="hidden" name="status" value="suspended">
                        <button class="button button-secondary button-small" type="submit">
                            Suspend this credential
                        </button>
                    </form>
                <?php elseif ($credential->status === \App\Domain\PractitionerCredentialStatus::Suspended): ?>
                    <form method="post"
                          action="/practitioners/credentials/<?= e((string) $credential->id) ?>/status"
                          class="inline-form">
                        <?= \App\Security\Csrf::field() ?>
                        <input type="hidden" name="status" value="verified">
                        <button class="button button-secondary button-small" type="submit">
                            Reinstate this credential
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!$credential->status->isFinal()): ?>
                    <form method="post"
                          action="/practitioners/credentials/<?= e((string) $credential->id) ?>/status"
                          class="stack">
                        <?= \App\Security\Csrf::field() ?>
                        <input type="hidden" name="status" value="revoked">
                        <div class="field">
                            <label for="revoke_<?= e((string) $credential->id) ?>">
                                Why this credential is being revoked
                            </label>
                            <input type="text" id="revoke_<?= e((string) $credential->id) ?>"
                                   name="note" minlength="10" maxlength="500" required>
                        </div>
                        <div class="form-actions">
                            <button class="button button-secondary button-small" type="submit">
                                Revoke this credential
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($eligible !== []): ?>
        <h2 class="section-title">Record a verification</h2>
        <p class="muted">
            The evidence reference is required. A verification whose evidence cannot be named
            is a record of somebody's confidence, and this register is not that.
        </p>
        <form method="post" action="/practitioners/credentials" class="stack" id="record-credential">
            <?= \App\Security\Csrf::field() ?>
            <div class="field">
                <label for="practitioner_id">Practitioner</label>
                <select id="practitioner_id" name="practitioner_id" required>
                    <?php foreach ($eligible as $candidate): ?>
                        <option value="<?= e((string) $candidate->id) ?>">
                            <?= e($candidate->name) ?> — <?= e($candidate->email) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="credential_type">Credential type</label>
                <input type="text" id="credential_type" name="credential_type"
                       maxlength="60" minlength="2" required>
            </div>

            <div class="field">
                <label for="registration_number">Registration number</label>
                <input type="text" id="registration_number" name="registration_number"
                       maxlength="60" minlength="2" required>
            </div>

            <div class="field">
                <label for="qualifications">Qualifications and training evidence</label>
                <input type="text" id="qualifications" name="qualifications" maxlength="500">
            </div>

            <div class="field">
                <label for="evidence_reference">Evidence reference</label>
                <input type="text" id="evidence_reference" name="evidence_reference"
                       maxlength="200" minlength="5" required>
            </div>

            <div class="field">
                <label for="institutional_privilege_status">Institutional privilege status, if applicable</label>
                <input type="text" id="institutional_privilege_status"
                       name="institutional_privilege_status" maxlength="60">
            </div>

            <div class="field">
                <label for="supervision_restriction">Supervision or delegation restriction, if any</label>
                <input type="text" id="supervision_restriction" name="supervision_restriction" maxlength="500">
            </div>

            <div class="field">
                <label for="effective_from">In force from</label>
                <input type="date" id="effective_from" name="effective_from" value="<?= e($today) ?>" required>
            </div>

            <div class="field">
                <label for="expires_on">Expires (leave empty only if the credential does not expire)</label>
                <input type="date" id="expires_on" name="expires_on">
            </div>

            <div class="form-actions">
                <button class="button" type="submit">Record the verification</button>
            </div>
        </form>
    <?php endif; ?>
</section>
