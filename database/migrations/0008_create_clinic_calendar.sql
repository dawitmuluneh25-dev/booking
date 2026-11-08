-- 0008_create_clinic_calendar.sql
-- Opening hours are clinic configuration, not code constants.
--
-- weekday follows ISO-8601 (1 = Monday … 7 = Sunday) so it matches the value
-- PHP returns from DateTimeImmutable::format('N'). Times are clinic-local wall
-- clock values; conversion to UTC happens at the booking boundary only.
--
-- clinic_closures holds one-off closed days (public holidays, staff training).
-- Both tables are seeded with a Mon–Fri 08:00–17:00 week so the application has
-- a working calendar immediately after migration.

CREATE TABLE clinic_hours (
    weekday TINYINT UNSIGNED NOT NULL,
    closed TINYINT(1) NOT NULL DEFAULT 0,
    opens TIME NOT NULL,
    closes TIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (weekday),
    CONSTRAINT ck_clinic_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT ck_clinic_hours_order CHECK (closed = 1 OR closes > opens)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO clinic_hours (weekday, closed, opens, closes, updated_at) VALUES
    (1, 0, '08:00:00', '17:00:00', UTC_TIMESTAMP()),
    (2, 0, '08:00:00', '17:00:00', UTC_TIMESTAMP()),
    (3, 0, '08:00:00', '17:00:00', UTC_TIMESTAMP()),
    (4, 0, '08:00:00', '17:00:00', UTC_TIMESTAMP()),
    (5, 0, '08:00:00', '17:00:00', UTC_TIMESTAMP()),
    (6, 1, '08:00:00', '17:00:00', UTC_TIMESTAMP()),
    (7, 1, '08:00:00', '17:00:00', UTC_TIMESTAMP());

CREATE TABLE clinic_closures (
    closed_on DATE NOT NULL,
    reason VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (closed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
