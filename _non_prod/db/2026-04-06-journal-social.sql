-- EMSP Docs
-- Journal social : likes et commentaires
-- Date: 2026-04-06

USE emsp_docs;

CREATE TABLE IF NOT EXISTS journal_likes (
  journal_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (journal_id, user_id),
  KEY idx_journal_likes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  status ENUM('visible','hidden','pending','deleted') NOT NULL DEFAULT 'visible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_journal_comments_journal_status (journal_id, status, created_at),
  KEY idx_journal_comments_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE journal_likes
  ADD CONSTRAINT fk_journal_likes_journal
    FOREIGN KEY (journal_id) REFERENCES journal(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_likes_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE journal_comments
  ADD CONSTRAINT fk_journal_comments_journal
    FOREIGN KEY (journal_id) REFERENCES journal(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_comments_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;


