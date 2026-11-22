<?php

/**
 * The three public pages of HR-WEB-CONTENT-R1-001, as data.
 *
 * Transcribed from `policy.md` rather than written. Headings and sentences follow the
 * document; nothing was added to make a page read better, because these are the words the
 * policy owners approved.
 *
 * `author` names the document, not a person, for the same reason service-pages.php does: it
 * records where the words came from. `approver`, `approved_on`, `effective_on` and `review_on`
 * are deliberately absent. Without them `PublishedContent::missingForPublication()` reports the
 * record as incomplete, so `PublishContent` refuses it. That is the intended state: these pages
 * can be reviewed, corrected and imported, and cannot go public until someone supplies the
 * provenance in policy.md's publication readiness note.
 *
 * Nine bracketed values in the source document are omitted rather than carried over as
 * placeholders, per that note: "publish the page without that section rather than with an
 * unfilled marker". The omissions are:
 *
 *   Privacy §5   — the security and access-control description (bracketed field list)
 *   Privacy §6   — retention rules, omitted in full; nothing in the section survives the marker
 *   Privacy §10  — cookies, analytics and third-party processors, omitted in full
 *   Booking §7   — rescheduling, cancellation and non-attendance rules
 *   Booking §8   — fees, payment, refunds, omitted in full
 *   Referral §1  — the approved clinician referral route
 *   Referral §4  — the alternative document-submission route
 *
 * When a value arrives, add it to the matching section here and re-import. The document remains
 * the source; this file is its transcription.
 */

declare(strict_types=1);

return [
    [
        'key' => 'policy.privacy',
        'title' => 'Privacy Notice',
        'author' => 'HR-WEB-CONTENT-R1-001 policy pack',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'About this notice',
                'paragraphs' => [
                    'This notice explains how HakimeRAD Radiologic Consultancy intends to collect and use '
                        . 'information submitted through its website and interventional radiology '
                        . 'referral-intake service.',
                    'The service is intended to help patients, parents or guardians, and referring healthcare '
                        . 'professionals submit information for administrative intake, clinical review and '
                        . 'procedure coordination.',
                    'Submitting information through the website does not establish a doctor–patient '
                        . 'relationship, confirm clinical acceptance or create a procedure appointment.',
                ],
            ],
            [
                'heading' => 'Information we may collect',
                'paragraphs' => [
                    'We request only information relevant to receiving and reviewing a procedure request. '
                        . 'Depending on the request, this may include:',
                    'Information about medications, allergies or other clinical matters may be supplied where '
                        . 'known. Public intake information is not a substitute for a complete clinical '
                        . 'assessment or medication reconciliation.',
                    'Please do not submit passwords, account credentials, unrelated personal documents or '
                        . 'public PACS access credentials.',
                ],
                'bullets' => [
                    'Patient name and age or date of birth, where known.',
                    'Name and contact details of the person submitting the request.',
                    "The submitter's relationship to the patient.",
                    'Referring clinician and healthcare facility details, where available.',
                    'Requested procedure and a plain-language reason for the request.',
                    'Relevant anatomical site or target, if known.',
                    'Relevant imaging reports, investigation reports and referral documents, if available.',
                    'Preferred communication method or language, if provided.',
                ],
            ],
            [
                'heading' => 'How information is used',
                'paragraphs' => [
                    'Submitted information may be used to:',
                    'A request for urgent review is not automatically assigned a clinical priority. Clinical '
                        . 'priority and suitability are determined by an authorized practitioner.',
                ],
                'bullets' => [
                    'Receive and register a procedure request.',
                    'Review administrative completeness.',
                    'Enable assessment by an appropriately authorized clinical practitioner.',
                    'Contact the patient, guardian or referring clinician for clarification.',
                    'Coordinate the requested service after the required clinical decision.',
                    'Maintain records of relevant communications and workflow decisions.',
                ],
            ],
            [
                'heading' => 'Who may access the information',
                'paragraphs' => [
                    'Access to patient and referral information is intended to be restricted to personnel '
                        . 'authorized to perform the relevant intake, administrative, clinical or '
                        . 'coordination responsibilities.',
                    'Where necessary for referral assessment and care coordination, information may need to be '
                        . 'communicated to the relevant referring or receiving clinical team.',
                    'Uploaded referral documents and investigation reports are intended to be handled as '
                        . 'private documents, not publicly accessible material.',
                ],
            ],
            [
                'heading' => 'Storage and security',
                'paragraphs' => [
                    'HakimeRAD intends to protect submitted information through access restrictions and '
                        . 'appropriate technical and organizational safeguards.',
                ],
            ],
            [
                'heading' => 'Access, correction and other privacy requests',
                'paragraphs' => [
                    'For questions or requests concerning personal information, contact:',
                    'Privacy contact: admin@hakimerad.com',
                    'Postal/business address: Harar, Ethiopia, PO Box 1194',
                ],
            ],
            [
                'heading' => 'Children and representatives',
                'paragraphs' => [
                    'A parent or guardian may submit information concerning a child. The submitter should '
                        . 'provide their identity and relationship to the patient.',
                    'A stated relationship alone does not establish legal authority to make decisions for '
                        . 'another person. Where required, HakimeRAD may need to verify representative '
                        . 'authority under the applicable approved policy before proceeding.',
                ],
            ],
            [
                'heading' => 'Emergency situations',
                'paragraphs' => [
                    'The website is not an emergency service. If a patient needs urgent medical assessment or '
                        . 'becomes acutely unwell, contact the treating hospital or emergency service '
                        . 'directly. Do not wait for an online response.',
                ],
            ],
        ],
    ],
    [
        'key' => 'policy.booking',
        'title' => 'Booking and Procedure Request Policy',
        'author' => 'HR-WEB-CONTENT-R1-001 policy pack',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'Purpose of the service',
                'paragraphs' => [
                    'HakimeRAD provides a referral-intake, clinical-review and procedure-coordination service '
                        . 'for selected image-guided interventional radiology procedures.',
                    'The website allows eligible visitors to submit a procedure request. It does not provide '
                        . 'automatic appointment confirmation or unrestricted public selection of procedure '
                        . 'dates and times.',
                ],
            ],
            [
                'heading' => 'Procedures covered',
                'paragraphs' => [
                    'Subject to confirmed service capability and clinical review, the Release 1 intake '
                        . 'service may accept requests for:',
                    'Hydrostatic intussusception reduction follows a separate urgent hospital-coordination '
                        . 'pathway. It is not an ordinary elective outpatient booking.',
                ],
                'bullets' => [
                    'Image-guided fine needle aspiration cytology (FNAC).',
                    'Image-guided core needle biopsy.',
                    'Image-guided abscess aspiration or drainage, as configured.',
                ],
            ],
            [
                'heading' => 'Submitting a request is not making an appointment',
                'paragraphs' => [
                    'A submitted request:',
                    'The submission acknowledgement confirms receipt only.',
                ],
                'bullets' => [
                    'Confirms that information has been sent to HakimeRAD.',
                    'Does not confirm that the requested procedure is appropriate.',
                    'Does not constitute clinical approval.',
                    'Does not reserve a procedure room, clinician, equipment or appointment slot.',
                    'Does not create a confirmed appointment.',
                ],
            ],
            [
                'heading' => 'How the process works',
                'paragraphs' => [
                    'The standard process is:',
                    'The clinical team, not the website or an automated priority selection, determines clinical '
                        . 'suitability and priority.',
                ],
                'bullets' => [
                    'Request submission: the patient, parent, guardian or appropriate referring party submits '
                        . 'the available information.',
                    'Administrative intake: the team reviews the submission for basic completeness.',
                    'Clinical review: an authorized practitioner assesses the request and supporting '
                        . 'information.',
                    'Further information, if required: the team may request additional documents or '
                        . 'clarification.',
                    'Clinical decision: the responsible practitioner records the decision and any conditions.',
                    'Operational coordination: required staff, equipment, room and supporting resources are '
                        . 'checked.',
                    'Scheduling: authorized staff arrange an appointment only after the required clinical '
                        . 'approval and resource confirmation.',
                    'Communication: the patient or responsible referring party is informed of the scheduling '
                        . 'decision and any approved next steps.',
                ],
            ],
            [
                'heading' => 'Urgent requests',
                'paragraphs' => [
                    'Selecting an urgent option or writing "urgent" in a request does not automatically '
                        . 'establish an emergency or assign clinical priority.',
                    'The clinical team determines the appropriate priority and pathway.',
                    'Hydrostatic intussusception reduction requires urgent hospital-based clinical assessment '
                        . 'and coordination. It is not a routine outpatient appointment. Do not wait for an '
                        . 'online booking response. Contact the referring hospital or emergency department '
                        . 'immediately using the verified emergency contact information provided.',
                ],
            ],
            [
                'heading' => 'Preparation and procedural consent',
                'paragraphs' => [
                    'Patient-specific preparation instructions must be issued by an authorized clinician.',
                    'The website does not establish universal fasting durations, medication interruption '
                        . 'schedules, laboratory thresholds, sedation instructions or other patient-specific '
                        . 'clinical requirements.',
                    "A patient's acceptance of this booking policy is not informed consent to a medical "
                        . 'procedure. Procedural consent must be handled separately through the clinic\'s '
                        . 'approved clinical consent process.',
                ],
            ],
            [
                'heading' => 'Appointment changes and cancellations',
                'paragraphs' => [
                    'Requests to reschedule or cancel must be handled through the approved HakimeRAD '
                        . 'communication route.',
                ],
            ],
            [
                'heading' => 'Availability and service limitations',
                'paragraphs' => [
                    'A request may require further clinical assessment, additional information or a different '
                        . 'care pathway. Scheduling is dependent on the clinical decision and confirmed '
                        . 'availability of the required service resources.',
                    'Submission does not guarantee that a procedure will be offered or performed.',
                ],
            ],
        ],
    ],
    [
        'key' => 'policy.referral',
        'title' => 'Referral Guidance',
        'author' => 'HR-WEB-CONTENT-R1-001 policy pack',
        'translation_review_status' => 'source',
        'sections' => [
            [
                'heading' => 'Who can submit a request?',
                'paragraphs' => [
                    'The Release 1 public intake route is intended to support limited referral intake from:',
                    'A patient or guardian submitting a public request does not automatically receive a patient '
                        . 'account, portal login or public access to referral records.',
                    'A professional title entered on a form does not itself establish verified professional '
                        . 'status or grant access to restricted referral records.',
                ],
                'bullets' => [
                    'Patients.',
                    'Parents or guardians submitting on behalf of a patient.',
                    'Referring healthcare professionals or hospital teams through an approved referral route.',
                ],
            ],
            [
                'heading' => 'Which requests can be submitted?',
                'paragraphs' => [
                    'Subject to the configured service catalogue, the public intake route may accept requests '
                        . 'for:',
                    'Hydrostatic intussusception reduction must not be submitted as an ordinary elective '
                        . 'appointment. It requires urgent hospital-based coordination.',
                ],
                'bullets' => [
                    'Image-guided FNAC.',
                    'Image-guided core needle biopsy.',
                    'Image-guided abscess aspiration or drainage.',
                ],
            ],
            [
                'heading' => 'What information should be provided?',
                'paragraphs' => [
                    'Please provide the information available to you. Do not delay urgent clinical assessment '
                        . 'because a document or item of information is unavailable.',
                    'Patient and submitter information:',
                    'Where available and relevant, attach a referral letter, relevant imaging report, '
                        . 'relevant investigation reports and any other clinical documents requested by the '
                        . 'referring team or reviewing clinician.',
                    'For clinician-originated referrals, include the clinical indication, the clinical question '
                        . 'to be addressed, relevant imaging information and a direct contact route for '
                        . 'clarification.',
                ],
                'bullets' => [
                    'Patient name.',
                    'Age or date of birth, if known.',
                    "Submitter's name and contact details.",
                    'Relationship to the patient, where applicable.',
                    'Parent or guardian details when relevant.',
                    'Requested procedure.',
                    'Plain-language reason for the request.',
                    'Target site or location, if known.',
                    'Referring clinician and facility, if available.',
                    'Contact information for the referring clinician or hospital team.',
                ],
            ],
            [
                'heading' => 'Document submission',
                'paragraphs' => [
                    'Only submit documents relevant to the referral.',
                    'Do not upload passwords, account credentials, unrelated personal documents or PACS access '
                        . 'credentials.',
                    'Uploaded documents are intended for review by authorized personnel and must not be '
                        . 'publicly accessible.',
                ],
            ],
            [
                'heading' => 'Requested urgency',
                'paragraphs' => [
                    'You may indicate that you believe a request needs expedited attention. This records the '
                        . "submitter's request only.",
                    'The responsible clinical practitioner determines the clinical priority. A request marked '
                        . 'urgent by a patient or referring party is not automatically approved as an emergency '
                        . 'procedure.',
                ],
            ],
            [
                'heading' => 'What happens after submission?',
                'paragraphs' => [
                    'After submission:',
                    'The online acknowledgement confirms receipt only. It does not mean that the referral has '
                        . 'been accepted, the procedure approved or an appointment scheduled.',
                    'There is no public referral-status lookup in Release 1. Use the verified contact route '
                        . 'for follow-up enquiries.',
                ],
                'bullets' => [
                    'The request is received for administrative intake.',
                    'The team may contact you or the referring clinician for further information.',
                    'An authorized practitioner reviews the clinical information.',
                    'The team records the clinical decision.',
                    'Scheduling can occur only after the required clinical approval and resource confirmation.',
                ],
            ],
            [
                'heading' => 'Important guidance for intussusception',
                'paragraphs' => [
                    'Hydrostatic intussusception reduction is an urgent hospital-based clinical pathway, not a '
                        . 'routine elective booking.',
                    'If intussusception is suspected or the patient needs urgent assessment, contact the '
                        . 'referring hospital or emergency department immediately. Do not wait for a website '
                        . 'response or an online referral acknowledgement.',
                ],
            ],
            [
                'heading' => 'Clinical decisions and patient instructions',
                'paragraphs' => [
                    'The referral website does not diagnose a condition or decide whether a procedure is '
                        . 'appropriate.',
                    'Do not change medication, fast, stop treatment or follow a procedure-specific preparation '
                        . 'schedule solely because a request has been submitted.',
                    'Follow patient-specific instructions issued by the responsible clinical team.',
                ],
            ],
        ],
    ],
];
