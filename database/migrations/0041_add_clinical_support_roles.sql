-- 0041_add_clinical_support_roles.sql
-- TECH, NUR and LAB become storable roles (Product Owner Decision 5, 14 November 2026).
--
-- The approved role matrix names nine internal roles; the application could store
-- six. That gap was a CHECK constraint, not a design: `ck_users_role` was widened in
-- 0009 for the same reason and with the same comment. A role the domain enum knows
-- and the database refuses is an account that cannot be created, so the two
-- vocabularies are widened together.
--
-- The values are the matrix's own abbreviations. Spelling them out would have meant
-- a governance document that says `LAB` and a column that says `laboratory`, and the
-- first audit question would be which one is authoritative.
--
-- No permission is granted here, and none should be read into it. Widening the
-- vocabulary makes the role name storable; what a role may do lives in
-- `Role::permissions()`, where Decision 5's restrictions are visible and testable.

ALTER TABLE users DROP CONSTRAINT ck_users_role;

ALTER TABLE users ADD CONSTRAINT ck_users_role CHECK (
    role IN ('admin', 'staff', 'radiologist', 'physician', 'coordinator', 'tech', 'nur', 'lab', 'patient')
);
