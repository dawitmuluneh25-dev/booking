<?php
/**
 * @var \App\Domain\Referral            $referral
 * @var \App\Domain\Procedure|null      $procedure
 * @var list<\App\Domain\ClinicalDecisionType> $decisions
 * @var list<\App\Domain\Priority>      $priorities
 * @var list<array{decision: \App\Domain\ClinicalDecision, reviewer: string, decidedAt: string, superseded: bool}> $history
 * @var \App\Domain\ClinicalPrivilegeCheck    $authority
 * @var array<string, string>           $errors
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Review <?= e($referral->reference) ?></h1>
        <a class="button button-secondary button-small" href="/review">Queue</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>The decision was not recorded.</p>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><a href="#<?= e((string) $field) ?>"><?= e($message) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <caption class="visually-hidden">Referral details</caption>
            <tbody>
                <tr>
                    <th scope="row">Patient</th>
                    <td><?= e($referral->patientName) ?></td>
                </tr>
                <tr>
                    <th scope="row">Procedure</th>
                    <td><?= e($procedure?->label ?? $referral->procedureCode) ?></td>
                </tr>
                <tr>
                    <th scope="row">Requested urgency</th>
                    <td><?= e($referral->requestedUrgencyLabel()) ?></td>
                </tr>
                <tr>
                    <th scope="row">Target site</th>
                    <td><?= e($referral->targetSite ?? '—') ?></td>
                </tr>
                <tr>
                    <th scope="row">Referring clinician</th>
                    <td><?= e($referral->referringPhysicianName ?? '—') ?></td>
                </tr>
                <tr>
                    <th scope="row">Clinical summary</th>
                    <td><?= nl2br(e($referral->clinicalSummary ?? '—')) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($history !== []): ?>
        <h2 class="section-title">Decision history</h2>
        <p class="muted">
            Decisions are never edited. A change of mind is a new decision, and the one it
            replaced is kept here, marked as no longer standing.
        </p>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Clinical decisions recorded on this referral</caption>
                <thead>
                    <tr>
                        <th scope="col">Outcome</th>
                        <th scope="col">Priority assigned</th>
                        <th scope="col">Rationale</th>
                        <th scope="col">Decided by</th>
                        <th scope="col">Decided</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $entry): ?>
                        <tr<?= $entry['superseded'] ? ' class="is-superseded"' : '' ?>>
                            <th scope="row">
                                <?= e($entry['decision']->type->label()) ?>
                                <?php if ($entry['superseded']): ?>
                                    <span class="badge badge-superseded">Superseded — no longer in force</span>
                                <?php else: ?>
                                    <span class="badge badge-current">In force</span>
                                <?php endif; ?>
                            </th>
                            <td><?= e($entry['decision']->clinicalPriorityLabel() ?? '—') ?></td>
                            <td><?= nl2br(e($entry['decision']->rationale)) ?></td>
                            <td><?= e($entry['reviewer']) ?></td>
                            <td><?= e($entry['decidedAt']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($currentUser->can(\App\Domain\Permission::ClinicalApprove)): ?>
        <h2 class="section-title">Approval authority</h2>
        <?php if ($authority->allowed): ?>
            <p>
                Your verified credential covers
                <strong><?= e($procedure?->label ?? 'this procedure') ?></strong>.
                <?php if ($authority->expiresOn() !== null): ?>
                    It is in force until <?= e((string) $authority->expiresOn()) ?>.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="alert alert-warning" role="status">
                <p><strong>Approval would be refused for you on this procedure.</strong></p>
                <p><?= e((string) $authority->reason) ?></p>
                <p class="muted">
                    The other outcomes remain available: declining, deferring and asking for
                    information release nothing into the scheduling queue, so they need no
                    procedure privilege.
                </p>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Decision</h2>
        <p class="muted">
            A rationale is required. The decision is recorded against your name and cannot
            be edited afterwards; a correction is a new decision.
        </p>

        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/decision" class="stack">
            <?= \App\Security\Csrf::field() ?>

            <div class="field">
                <label for="decision_type">Outcome</label>
                <select id="decision_type" name="decision_type" required>
                    <?php foreach ($decisions as $decision): ?>
                        <option value="<?= e($decision->value) ?>"><?= e($decision->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="clinical_priority">Clinical priority</label>
                <select id="clinical_priority" name="clinical_priority"
                        <?= isset($errors['clinical_priority']) ? ' aria-describedby="clinical_priority-error" aria-invalid="true"' : '' ?>>
                    <option value="">— Not stated —</option>
                    <?php foreach ($priorities as $priority): ?>
                        <option value="<?= e($priority->value) ?>"><?= e($priority->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">
                    Required when approving. Routine and expedited order the elective queue;
                    urgent direct coordination means the queue is the wrong route, so it is
                    recorded with a redirect to the receiving team rather than an approval.
                    Priority is recorded against your name and is never taken from the
                    submitter's request.
                </p>
                <?php if (isset($errors['clinical_priority'])): ?>
                    <p class="field-error" id="clinical_priority-error"><?= e($errors['clinical_priority']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="rationale">Rationale</label>
                <textarea id="rationale" name="rationale" rows="4" required minlength="10"
                          <?= isset($errors['rationale']) ? ' aria-describedby="rationale-error" aria-invalid="true"' : '' ?>></textarea>
                <?php if (isset($errors['rationale'])): ?>
                    <p class="field-error" id="rationale-error"><?= e($errors['rationale']) ?></p>
                <?php endif; ?>
            </div>

            <h3 class="section-title">If approved</h3>
            <div class="grid">
                <div class="field">
                    <label for="approved_technique">Approved technique</label>
                    <input id="approved_technique" name="approved_technique" type="text">
                </div>
                <div class="field">
                    <label for="modality">Modality</label>
                    <input id="modality" name="modality" type="text">
                </div>
                <div class="field">
                    <label for="sedation">Sedation</label>
                    <input id="sedation" name="sedation" type="text">
                </div>
                <div class="field">
                    <label for="estimated_duration_minutes">Estimated minutes</label>
                    <input id="estimated_duration_minutes" name="estimated_duration_minutes" type="text" inputmode="numeric">
                </div>
            </div>

            <div class="field">
                <label for="preprocedure_conditions">Pre-procedure conditions</label>
                <textarea id="preprocedure_conditions" name="preprocedure_conditions" rows="3"></textarea>
            </div>

            <h3 class="section-title">If redirected or more information is needed</h3>
            <div class="grid">
                <div class="field">
                    <label for="redirect_destination">Redirect to</label>
                    <input id="redirect_destination" name="redirect_destination" type="text">
                </div>
                <div class="field">
                    <label for="information_requested">Information requested</label>
                    <textarea id="information_requested" name="information_requested" rows="2"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">Record decision</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">You can see this referral but not decide it.</p>
    <?php endif; ?>
</section>
