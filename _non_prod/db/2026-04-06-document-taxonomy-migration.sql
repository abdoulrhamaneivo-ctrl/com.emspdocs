-- EMSP Docs
-- Migration taxonomie acadÃ©mique + multi-filiÃ¨re
-- Date: 2026-04-06

USE emsp_docs;

ALTER TABLE matieres
  MODIFY module_id INT UNSIGNED NULL;

ALTER TABLE documents
  ADD COLUMN matiere_label_pending VARCHAR(191) NULL AFTER matiere_id;

CREATE TABLE IF NOT EXISTS document_filieres (
  document_id INT UNSIGNED NOT NULL,
  filiere_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, filiere_id),
  KEY idx_document_filieres_filiere (filiere_id),
  CONSTRAINT fk_document_filieres_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_document_filieres_filiere
    FOREIGN KEY (filiere_id) REFERENCES filieres(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO document_filieres (document_id, filiere_id)
SELECT d.id, d.filiere_id
FROM documents d
WHERE d.filiere_id IS NOT NULL
ON DUPLICATE KEY UPDATE filiere_id = VALUES(filiere_id);


