-- D-09's last silent failure: a schedule that was never installed.
--
-- `notify:queue-health` infers a missing dispatcher from old pending rows. That
-- works only while there are rows to be old — an empty queue on a fresh
-- deployment reports healthy, and it keeps reporting healthy until the first
-- booking arrives and then sits there. The queue is the wrong witness: it
-- measures work waiting, not whether anybody is looking.
--
-- So the dispatcher records that it ran. An empty queue with a run ten minutes
-- ago is a quiet clinic; an empty queue with no run ever is a cron entry nobody
-- added, and those two states are indistinguishable from the outbox alone.
--
-- One row per invocation, written after the run rather than before: a row that
-- exists means the process reached its own end, which is what the check is
-- asking. A run that crashed mid-dispatch leaves the previous row as the
-- evidence, and its age is the truth about when the schedule last completed.

CREATE TABLE dispatch_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    claimed INT UNSIGNED NOT NULL,
    sent INT UNSIGNED NOT NULL,
    retrying INT UNSIGNED NOT NULL,
    dead INT UNSIGNED NOT NULL,
    host VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dispatch_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
