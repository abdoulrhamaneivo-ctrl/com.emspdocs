-- EMSP Docs
-- Migration cycle de vie du journal
-- Date: 2026-04-06

USE emsp_docs;

ALTER TABLE journal
  ADD COLUMN starts_at DATETIME NULL AFTER status,
  ADD COLUMN ends_at DATETIME NULL AFTER starts_at,
  ADD COLUMN closed_at DATETIME NULL AFTER ends_at,
  ADD COLUMN closed_by INT UNSIGNED NULL AFTER closed_at,
  ADD KEY idx_journal_starts_at (starts_at),
  ADD KEY idx_journal_ends_at (ends_at),
  ADD KEY idx_journal_closed_at (closed_at),
  ADD KEY idx_journal_closed_by (closed_by);

ALTER TABLE journal
  ADD CONSTRAINT fk_journal_closed_by
    FOREIGN KEY (closed_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;


