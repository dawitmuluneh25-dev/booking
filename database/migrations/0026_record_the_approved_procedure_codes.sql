-- 0026_record_the_approved_procedure_codes.sql
-- Two vocabularies for four services, tied together in the schema.
--
-- HR-IR-CLIN-R1-001 §3.1 names the Release 1 services as `FNAC`, `CORE_BIOPSY`,
-- `ABSCESS_DRAINAGE` and `HYDROSTATIC_INTUSSUSCEPTION`. The catalogue stores
-- `image_guided_fnac`, `image_guided_core_biopsy`, `image_guided_abscess_drainage`
-- and `hydrostatic_intussusception_reduction`. Both are stable; they are not the
-- same string.
--
-- Rewriting the stored codes was rejected: `referrals.procedure_code` is a
-- foreign key on live rows, the retired combined code exists so old referrals keep
-- resolving to a name, and a rename would touch every referral to change an
-- identifier that no patient or referrer ever sees. Instead the approved code is
-- recorded next to the catalogue code, so the mapping is one row in one table
-- rather than a table in a document that code cannot be tested against.
--
-- The retired `image_guided_biopsy` deliberately gets no clinical code. It stood
-- for both FNAC and core biopsy at once, which is precisely why it was retired;
-- mapping it to either would claim the old referrals recorded a technique nobody
-- wrote down. The unique key also means no catalogue code can claim two approved
-- identities.
--
-- NULL is allowed because a locally added service has no approved code to carry,
-- and the absence is honest. Multiple NULLs are permitted by a MySQL unique index,
-- so the constraint does not force every row to be mapped.

-- 40 is the width of `procedures.code`, and it has to be: the longest approved
-- code is HYDROSTATIC_INTUSSUSCEPTION at 27 characters. A narrower column fails
-- on the last UPDATE, after the DDL has already been applied, which is how this
-- migration first failed.
ALTER TABLE procedures
    ADD COLUMN clinical_code VARCHAR(40) NULL AFTER label;

ALTER TABLE procedures
    ADD UNIQUE KEY uq_procedures_clinical_code (clinical_code);

UPDATE procedures SET clinical_code = 'FNAC', updated_at = UTC_TIMESTAMP()
WHERE code = 'image_guided_fnac';

UPDATE procedures SET clinical_code = 'CORE_BIOPSY', updated_at = UTC_TIMESTAMP()
WHERE code = 'image_guided_core_biopsy';

UPDATE procedures SET clinical_code = 'ABSCESS_DRAINAGE', updated_at = UTC_TIMESTAMP()
WHERE code = 'image_guided_abscess_drainage';

UPDATE procedures SET clinical_code = 'HYDROSTATIC_INTUSSUSCEPTION', updated_at = UTC_TIMESTAMP()
WHERE code = 'hydrostatic_intussusception_reduction';
