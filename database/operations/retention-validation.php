<?php

/**
 * D-02's validation record: who validated the retention schedule, when, over what, and against which
 * version of it.
 *
 * This is the evidence `release:gates` reads for the "Retention activation" row, and it replaces the
 * paragraph in `docs/operations/retention-validation.md` as the thing the gate checks. The paragraph
 * still explains the validation; this file is what a machine can compare.
 *
 * **The record is current, and this paragraph is why it was not, until 26 November 2026.** Gemechisa
 * Dalu validated the schedule on 25 November 2026. The next day the table changed: four classes moved,
 * `dispatch_runs` was added, and the audit trail moved back to seven years. Between those two dates the
 * record reads `stale` — the fingerprint it carried no longer described the table — and the gate said
 * so rather than repeating an approval that had stopped covering what it named.
 *
 * On 26 November the same approver confirmed the table as it then stood, including the two classes
 * that still have a period and no sweep. That confirmation is the one recorded below: date 26 November,
 * fingerprint of the table printed by `retention:plan` at that moment. The `stale` state is not a stage
 * the mechanism skipped; it is what the mechanism was built to produce, and it produced it for a few
 * hours before a person closed it — the amendment and the re-confirmation fall on the same calendar day,
 * which is worth noting rather than smoothing over.
 *
 * How to complete it, once the office has confirmed:
 *
 *   1. Run `php bin/console.php retention:plan`. It prints the schedule it executes and that
 *      schedule's fingerprint. Read the table with the validator — the periods per class, the 35-day
 *      backup maximum and the 14-copy cap.
 *   2. Only if they confirm the periods, the lawful basis, the preservation exceptions and the
 *      applicable Ethiopian requirements, update `approved_on` to the date of that confirmation and
 *      `schedule_fingerprint` to the value on screen at the time. Do not compute the fingerprint in
 *      this file: a literal is the only thing that can go stale.
 *   3. Commit. The commit is the provenance: who filled it in, when, and against which code.
 *
 * If the schedule changes again, the literal fingerprint stops matching and the gate drops back to
 * `partial` by itself. That is the design, not an obstacle: it means the change needs a validation.
 */

return [
    // The person in Legal / Data Protection who confirmed the schedule.
    'approver' => 'Gemechisa Dalu',

    // The office they act for. D-02 names it; a record approved by any other office is incomplete.
    'office' => 'Legal / Data Protection',

    // The date of the confirmation that covers the table below — 26 November 2026, re-confirming the
    // periods adopted on the 26th. The earlier 25 November validation is recorded in
    // docs/operations/retention-validation.md §1 and §7; it is history, not the basis for this row.
    'approved_on' => '2026-11-26',

    // What was validated. Quoted from the document rather than paraphrased, so the two cannot drift.
    'scope' => 'The periods, the lawful basis for holding each class, the preservation exceptions D-02 '
        . 'names, and the applicable Ethiopian requirements — as the schedule stood on 26 November 2026, '
        . 'including the 26 November amendment.',

    // The value `retention:plan` printed when the confirmation was given. A literal on purpose: see the
    // header. Changing a period, adding a class or moving the backup limits makes this line stale on
    // the commit that does it, which is the whole point of keeping it here rather than computing it.
    'schedule_fingerprint' => 'cad40459f901073f26e3a6901a2a4150cf6b4fc22a186623eb2525d9780fedce',

    'notes' => 'Confirmed for the table as it stood on 26 November 2026: clinical_and_procedure 1,825 '
        . 'days, referral_and_intake 2,557, uploaded_clinical_documents 1,825, '
        . 'appointment_and_administrative 2,557, notification_and_outbox 365, security_and_audit 2,557, '
        . 'dispatch_runs 1,825, backups 35 days maximum and 14 copies. The confirmation was given with '
        . 'the outstanding coverage known: clinical_and_procedure and security_and_audit have periods '
        . 'and no sweep behind them, so nothing applies those two periods yet. That is a coverage gap '
        . 'reported by retention:plan, not a defect in the periods, and it does not qualify this '
        . 'approval — it is recorded so the next reader knows the signatory saw it.',
];
