<?php

declare(strict_types=1);

/**
 * The four service pages of HR-IR-CLIN-R1-001 §17.1–§17.4, as data.
 *
 * The words here are the package's English master drafts, transcribed rather than
 * rewritten. Where the package uses a heading, this file uses the same heading;
 * where it lists bullet points, this file lists the same points. Nothing was added
 * to make a page read better, because every sentence on a page like this is
 * clinical copy and there is no version of "improving" it that is not somebody
 * other than its approver writing it.
 *
 * §17.5's provenance, as supplied by the clinical owner:
 *
 *   `author` — the package, not a person. It records where the words came from.
 *   `clinical_reviewer` and `approver` — both Dawit Muluneh. §17.5 lists them as
 *   separate records, which they remain: one field saying "the same person did
 *   both" would lose the fact that two acts were asked for.
 *   `approved_on` and `effective_on` — 2026-11-13 for both, because the copy was
 *   approved and takes effect on the same day.
 *
 * `review_on` — 2027-11-13, one year after approval.
 *
 * This one is our assumption rather than a supplied fact, and the distinction is
 * worth keeping visible. The date supplied with the other two was 2026-11-11,
 * which falls two days *before* the approval it would be reviewing and before
 * every other date on the record: stored as given it would fail
 * `ck_published_content_review_after_approval`, and even if it did not, it would
 * make the page expired on the day it was published — live for no time at all.
 * The clinical owner then asked for "a logical date" without naming an interval.
 * A twelve-month review is the ordinary cadence for patient-facing clinical copy,
 * so the date follows from the approval by exactly that. It is one field per page,
 * so replacing it with a different interval is a change to four lines and no
 * migration.
 *
 * `author` is the package, not a person: it records where the words came from.
 *
 * `version` and `superseded_version` — absent on `service.intussusception`, which still cannot be
 * published (D-03). The three procedure pages carry `1.1` / `1.0` because C4 corrected copy that was
 * already published: `content:sync` never rewrites a published row, and the row's identity includes
 * the version, so a correction without a new version is an import that reports success and changes
 * nothing. A page that supersedes something must say so; `ContentSource` refuses the half-edit.
 *
 * @return list<array{
 *     key: string,
 *     title: string,
 *     author: string,
 *     approver: ?string,
 *     approved_on: ?string,
 *     clinical_reviewer: ?string,
 *     effective_on: ?string,
 *     review_on: ?string,
 *     translation_review_status: ?string,
 *     version?: string,
 *     superseded_version?: string,
 *     sections: list<array<string, mixed>>
 * }>
 */

return [
    [
        'key' => 'service.fnac',
        'title' => 'Image-Guided Fine Needle Aspiration Cytology (FNAC)',
        // C4: corrected after publication, so it carries its own version. See §42 of the plan.
        'version' => '1.1',
        'superseded_version' => '1.0',
        'author' => 'HR-IR-CLIN-R1-001 clinical package',
        'approver' => 'Dawit Muluneh',
        'approved_on' => '2026-11-13',
        'clinical_reviewer' => 'Dawit Muluneh',
        'effective_on' => '2026-11-13',
        'review_on' => '2027-11-13',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'What it is',
                'paragraphs' => [
                    'FNAC is a sampling procedure in which a clinician uses a fine needle, with imaging '
                        . 'guidance where appropriate, to collect cells or material from a selected area '
                        . 'for laboratory assessment.',
                ],
            ],
            [
                'heading' => 'Why it may be requested',
                'paragraphs' => [
                    'A treating clinician may request FNAC to help investigate a finding identified '
                        . 'through clinical assessment or imaging. Whether FNAC is appropriate depends on '
                        . 'the target, the clinical question, the available imaging and the assessment of '
                        . 'the interventional radiology team.',
                ],
            ],
            [
                'heading' => 'What happens after you submit a request',
                'paragraphs' => [
                    'The team reviews the referral information and any available reports. It may request '
                        . 'additional information, imaging or clinical assessment. If the procedure is '
                        . 'considered appropriate and the required resources are available, authorized '
                        . 'staff will contact you about scheduling.',
                ],
            ],
            [
                'heading' => 'Information that may be requested',
                'bullets' => [
                    'Referral letter or clinician contact details.',
                    'Relevant imaging report or secure imaging reference.',
                    'The area or finding being investigated.',
                    'Relevant previous sampling or procedure information.',
                    'Other information requested by the reviewing clinician.',
                ],
            ],
            [
                'heading' => 'Preparation and consent',
                'paragraphs' => [
                    'Do not stop or change medicines, fast or follow a preparation routine based only on '
                        . 'this webpage. If preparation is needed, an authorized clinician will issue '
                        . 'instructions for the individual procedure. The clinician will discuss the '
                        . 'proposed procedure, its expected benefits, material risks and alternatives and '
                        . 'answer questions before obtaining consent under the approved local process.',
                ],
            ],
            [
                'heading' => 'After the procedure',
                'paragraphs' => [
                    'The team will explain the expected next steps, including specimen handling and how '
                        . 'the result will be followed up. Follow the individual instructions given by the '
                        . 'treating team.',
                ],
            ],
            [
                // C-5 §C11, transcribed. The published page carried no urgent-care section at all,
                // which is the "do not wait for a response" divergence C4 records.
                'heading' => 'When to contact the treating team or seek urgent care',
                'paragraphs' => [
                    'Use the contact details provided by the treating team if there is a concern about '
                        . 'the sampling site, recovery or follow-up arrangements.',
                    'If the patient experiences a serious or rapidly worsening problem or needs urgent '
                        . 'assessment, seek medical care through the appropriate emergency service '
                        . 'rather than waiting for an online response.',
                    'The responsible clinician must provide the patient-specific warning signs and '
                        . 'contact instructions.',
                ],
            ],
            [
                'heading' => 'Frequently asked questions',
                'faq' => [
                    [
                        'question' => 'Does submitting this form confirm my procedure?',
                        'answer' => 'No. The request must first undergo clinical review and resource '
                            . 'confirmation.',
                    ],
                    [
                        'question' => 'Can I choose the needle or number of samples?',
                        'answer' => 'No. Technical decisions are made by the performing practitioner.',
                    ],
                    [
                        'question' => 'Will I receive a result from the booking system?',
                        'answer' => 'The PWA may track specimen logistics and follow-up responsibility; '
                            . 'it is not the pathology reporting system.',
                    ],
                ],
            ],
        ],
    ],
    [
        'key' => 'service.core_biopsy',
        'title' => 'Image-Guided Core Needle Biopsy',
        // C4: corrected after publication, so it carries its own version. See §42 of the plan.
        'version' => '1.1',
        'superseded_version' => '1.0',
        'author' => 'HR-IR-CLIN-R1-001 clinical package',
        'approver' => 'Dawit Muluneh',
        'approved_on' => '2026-11-13',
        'clinical_reviewer' => 'Dawit Muluneh',
        'effective_on' => '2026-11-13',
        'review_on' => '2027-11-13',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'What it is',
                'paragraphs' => [
                    'A core needle biopsy is a procedure in which a clinician uses an image-guided needle '
                        . 'to obtain tissue samples from a selected area for laboratory examination.',
                ],
            ],
            [
                'heading' => 'Why it may be requested',
                'paragraphs' => [
                    'A treating clinician may request a core biopsy when tissue assessment is needed to '
                        . 'investigate a finding or answer a specific clinical question. The reviewing '
                        . 'practitioner determines whether the requested method and guidance approach are '
                        . 'appropriate.',
                ],
            ],
            [
                'heading' => 'What happens after you submit a request',
                'paragraphs' => [
                    'The team reviews the clinical question, target information and available imaging. '
                        . 'Additional reports, imaging or clinical information may be requested. If the '
                        . 'procedure is approved and the required resources are available, authorized '
                        . 'staff will contact you to arrange the appointment.',
                ],
            ],
            [
                'heading' => 'Information that may be requested',
                'bullets' => [
                    'Referral letter and referring clinician contact.',
                    'Target location and relevant imaging report.',
                    'Previous biopsy or relevant intervention history.',
                    'Relevant medical and medication information.',
                    'Any pathology processing requirements identified by the clinical team.',
                ],
            ],
            [
                'heading' => 'Preparation and consent',
                'paragraphs' => [
                    'Do not stop medicines or fast based on general webpage information. Individual '
                        . 'preparation instructions will be provided by an authorized clinician if needed. '
                        . 'The clinician will explain the procedure, potential benefits, material risks and '
                        . 'alternatives and will address questions before the consent process.',
                    // C-5 §D8, transcribed word for word: the anticoagulant warning C4 records as missing.
                    'Do not stop anticoagulants, antiplatelet medicines or other medicines without a '
                        . 'specific clinical instruction. Do not apply a generic fasting or laboratory '
                        . 'rule to your own procedure.',
                ],
            ],
            [
                'heading' => 'After the procedure',
                'paragraphs' => [
                    'The team will document the sample handover and identify the clinician or team '
                        . 'responsible for result follow-up. The booking system does not interpret '
                        . 'pathology results.',
                ],
            ],
            [
                // C-5 §D11, transcribed.
                'heading' => 'When to contact the treating team or seek urgent care',
                'paragraphs' => [
                    'Follow the warning signs and contact instructions provided by the treating team.',
                    'Contact the team if there is a concern about the biopsy site, recovery, results or '
                        . 'follow-up.',
                    'If the patient develops a serious or rapidly worsening problem or needs urgent '
                        . 'assessment, seek care through the appropriate emergency service immediately.',
                    'The treating clinician must specify the relevant patient-specific warning signs '
                        . 'before discharge.',
                ],
            ],
            [
                'heading' => 'Frequently asked questions',
                'faq' => [
                    [
                        'question' => 'Is core biopsy the same as FNAC?',
                        'answer' => 'No. They are different sampling methods and are reviewed as separate '
                            . 'request types.',
                    ],
                    [
                        'question' => 'Does approval guarantee a specific date?',
                        'answer' => 'No. Scheduling depends on the approved plan and actual staff, '
                            . 'equipment and room availability.',
                    ],
                    [
                        'question' => 'Will the website tell me how to change my medicines?',
                        'answer' => 'No. Medication decisions are individualized and must come from the '
                            . 'treating clinician.',
                    ],
                ],
            ],
        ],
    ],
    [
        'key' => 'service.abscess_drainage',
        // C-5 §E1 says "Abscess Aspiration and Drainage". The published title dropped "Aspiration
        // and", which is the first of C4's five divergences: a page that names one of two approaches
        // is a page that has quietly decided the other one does not exist.
        'title' => 'Image-Guided Abscess Aspiration and Drainage',
        // C4: corrected after publication, so it carries its own version. See §42 of the plan.
        'version' => '1.1',
        'superseded_version' => '1.0',
        'author' => 'HR-IR-CLIN-R1-001 clinical package',
        'approver' => 'Dawit Muluneh',
        'approved_on' => '2026-11-13',
        'clinical_reviewer' => 'Dawit Muluneh',
        'effective_on' => '2026-11-13',
        'review_on' => '2027-11-13',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'What it is',
                'paragraphs' => [
                    'Image-guided abscess drainage is a procedure in which a clinician uses imaging to '
                        . 'guide a needle or drainage catheter to a fluid collection when drainage is '
                        . 'considered appropriate. Depending on the clinical assessment, the planned '
                        . 'approach may be aspiration or catheter drainage.',
                ],
            ],
            [
                'heading' => 'Why it may be requested',
                'paragraphs' => [
                    'A treating clinician may request drainage to obtain a sample, remove fluid or support '
                        . 'treatment of a collection. The interventional radiology team reviews the '
                        . 'clinical information and imaging before deciding whether and how an intervention '
                        . 'should be performed.',
                ],
            ],
            [
                'heading' => 'What happens after you submit a request',
                'paragraphs' => [
                    'The team reviews the collection location, available imaging, clinical context and '
                        . 'proposed objective. It may request additional information or consultation with '
                        . 'other treating teams. Scheduling occurs only after clinical approval and '
                        . 'resource confirmation.',
                ],
            ],
            [
                'heading' => 'Information that may be requested',
                'bullets' => [
                    'Referral letter and treating clinician contact.',
                    'Collection location and available imaging.',
                    'Relevant history and previous treatment information.',
                    'Relevant investigation or microbiology information, if available.',
                    'Other details requested by the reviewing clinician.',
                ],
            ],
            [
                'heading' => 'Preparation and consent',
                'paragraphs' => [
                    'Do not change medication, fast or follow catheter-care instructions based only on this '
                        . 'webpage. The responsible team will provide individual instructions and discuss '
                        . 'the procedure, benefits, risks and alternatives as part of the consent process.',
                ],
            ],
            [
                'heading' => 'After the procedure',
                'paragraphs' => [
                    'If a drain is placed, the treating team will identify who is responsible for '
                        . 'follow-up and provide the appropriate contact route and individual instructions. '
                        . 'The booking system does not decide when a catheter should be removed or changed.',
                    // C-5 §E10, transcribed word for word: the catheter-care warning C4 records as
                    // missing. It is the one instruction on this page that a patient could act on to
                    // hurt themselves, so it is carried in the package's own emphatic wording.
                    'Do not attempt to flush, remove, reposition or otherwise manipulate a drainage '
                        . 'catheter unless the responsible clinical team has specifically instructed and '
                        . 'trained you to do so under an approved local protocol.',
                ],
            ],
            [
                // C-5 §E11, transcribed.
                'heading' => 'When to contact the treating team or seek urgent care',
                'paragraphs' => [
                    'The treating team must provide individualized warning signs and a contact route.',
                    'Contact the treating team if there is a concern about the drain, procedure site, '
                        . 'drainage instructions, results or planned review.',
                    'If the patient becomes acutely unwell, develops a serious or rapidly worsening '
                        . 'problem, or requires urgent medical assessment, contact the treating hospital '
                        . 'or emergency service immediately.',
                    'Do not wait for an online response.',
                ],
            ],
            [
                'heading' => 'Frequently asked questions',
                'faq' => [
                    [
                        'question' => 'Will every abscess receive a catheter?',
                        'answer' => 'No. The clinician determines whether aspiration, catheter drainage or '
                            . 'another pathway is appropriate.',
                    ],
                    [
                        'question' => 'Will a sample be sent to a laboratory?',
                        'answer' => 'This depends on the clinical plan. If sampling is planned, the '
                            . 'destination and handling arrangements must be confirmed.',
                    ],
                    [
                        'question' => 'Who manages the drain afterward?',
                        'answer' => 'The responsible clinical team will be identified in the follow-up '
                            . 'plan.',
                    ],
                ],
            ],
        ],
    ],
    [
        // HR-ENG-DEC-R1-003 D-03 (22 November 2026) restricts this pathway to urgent, hospital-based
        // coordination and supplies the public wording permitted for it. This entry records three
        // things a future reader would otherwise have to reconstruct:
        //
        //   **The sign-off arrived.** D-03 made publication conditional on a documented sign-off by
        //   the Clinical Approver *and* the receiving paediatric/surgical authority. Both were given
        //   on 25 November 2026: **Dawit Muluneh** (Clinical Approver) and **Dawit Ayele**
        //   (receiving clinical authority). `approver` and `clinical_reviewer` carry the first;
        //   the repository has no field for a second institution's signature, which is why the pair
        //   is recorded here and in docs/clinical/intussusception-signoff.md rather than invented as
        //   a schema column.
        //
        //   **The paragraph budget was amended (D-14, 25 November 2026).** D-03 authorised one
        //   paragraph, and a test enforced exactly one. That budget left the page unable to say the
        //   single most important thing about it, because C-5's intussusception urgent message (§G8)
        //   contains a sentence that is *not* in the record's paragraph: "HakimeRAD's online platform
        //   is not an emergency service." The approving owner authorised one additional approved
        //   paragraph, so the page now carries the record's paragraph and that sentence verbatim,
        //   and nothing else. `IntussusceptionCopyTest` pins both and still refuses a third.
        //
        //   The sections removed on 23 November described how coordination runs — present tense, as a
        //   service in operation — which is the public availability claim D-03 forbids. They stay
        //   removed. No replacement prose was written, then or now.
        'key' => 'service.intussusception',
        'title' => 'Hydrostatic Intussusception Reduction — Urgent Hospital Coordination',
        // Corrected after publication, so it carries its own version and names what it replaces.
        'version' => '1.1',
        'superseded_version' => '1.0',
        'author' => 'HR-IR-CLIN-R1-001 clinical package',
        'approver' => 'Dawit Muluneh',
        'approved_on' => '2026-11-13',
        'clinical_reviewer' => 'Dawit Muluneh',
        'effective_on' => '2026-11-25',
        'review_on' => '2027-11-13',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'Important',
                'paragraphs' => [
                    'Hydrostatic reduction of intussusception is an urgent, hospital-based procedure '
                        . 'requiring assessment and coordination with the receiving clinical team, '
                        . 'including appropriate paediatric and surgical involvement. Submitting an '
                        . 'online request does not confirm clinical acceptance, suitability, or '
                        . 'procedure availability. If a child may have intussusception or has '
                        . 'concerning symptoms, seek immediate assessment at a hospital or emergency '
                        . 'department. Do not wait for an online response.',
                    // C-5 §G8, final sentence, transcribed word for word under D-14. It states what
                    // the service is not, which is the one claim the page can make safely before any
                    // coordination has happened.
                    'HakimeRAD\'s online platform is not an emergency service.',
                ],
            ],
        ],
    ],
];
