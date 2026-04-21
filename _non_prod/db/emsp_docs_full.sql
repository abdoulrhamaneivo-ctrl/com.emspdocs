-- EMSP Docs full schema (all current features)
-- MySQL / MariaDB, utf8mb4

CREATE DATABASE IF NOT EXISTS emsp_docs
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE emsp_docs;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('etudiant','moderateur','admin') NOT NULL DEFAULT 'etudiant',
  status ENUM('active','pending','suspended','rejected') NOT NULL DEFAULT 'pending',
  badge_level ENUM('none','bronze','argent','or') NOT NULL DEFAULT 'none',
  upload_count INT UNSIGNED NOT NULL DEFAULT 0,
  photo_path VARCHAR(255) DEFAULT NULL,
  filiere_id INT UNSIGNED DEFAULT NULL,
  licence_id INT UNSIGNED DEFAULT NULL,
  registration_method ENUM('school_email','manual_card') NOT NULL DEFAULT 'school_email',
  student_card_path VARCHAR(255) DEFAULT NULL,
  verification_token VARCHAR(255) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  status_updated_at DATETIME DEFAULT NULL,
  reset_token VARCHAR(255) DEFAULT NULL,
  reset_token_expires_at DATETIME DEFAULT NULL,
  rejection_reason TEXT DEFAULT NULL,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_filiere (filiere_id),
  KEY idx_users_licence (licence_id),
  KEY idx_users_status (status),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academic references
CREATE TABLE IF NOT EXISTS filieres (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) NOT NULL,
  cover_image_path VARCHAR(255) DEFAULT NULL,
  summary TEXT DEFAULT NULL,
  description_html MEDIUMTEXT DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_filieres_code (code),
  KEY idx_filieres_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licences (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_licences_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licence_filieres (
  licence_id INT UNSIGNED NOT NULL,
  filiere_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (licence_id, filiere_id),
  KEY idx_licence_filieres_filiere (filiere_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  licence_id INT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_modules_licence (licence_id),
  KEY idx_modules_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matieres (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  module_id INT UNSIGNED DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_matieres_module (module_id),
  KEY idx_matieres_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents
CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uploader_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  doc_type ENUM('cours','td','correction','concours','examen') NOT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  filiere_id INT UNSIGNED DEFAULT NULL,
  licence_id INT UNSIGNED DEFAULT NULL,
  module_id INT UNSIGNED DEFAULT NULL,
  matiere_id INT UNSIGNED DEFAULT NULL,
  matiere_label_pending VARCHAR(191) DEFAULT NULL,
  exam_session VARCHAR(50) DEFAULT NULL,
  exam_section VARCHAR(50) DEFAULT NULL,
  exam_year INT DEFAULT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size_bytes INT UNSIGNED NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  file_hash CHAR(64) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason TEXT DEFAULT NULL,
  approved_by INT UNSIGNED DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  like_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_hash (file_hash),
  KEY idx_documents_status_public_created (status, is_public, created_at),
  KEY idx_documents_uploader (uploader_id),
  KEY idx_documents_filiere (filiere_id),
  KEY idx_documents_licence (licence_id),
  KEY idx_documents_module (module_id),
  KEY idx_documents_matiere (matiere_id),
  KEY idx_documents_type (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_filieres (
  document_id INT UNSIGNED NOT NULL,
  filiere_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, filiere_id),
  KEY idx_document_filieres_filiere (filiere_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Favorites and likes
CREATE TABLE IF NOT EXISTS favorites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fav (user_id, document_id),
  KEY idx_favorites_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_likes (
  user_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, document_id),
  KEY idx_document_likes_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  status ENUM('visible','hidden','pending','deleted') NOT NULL DEFAULT 'visible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_document_status (document_id, status, created_at),
  KEY idx_comments_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_replies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  status ENUM('visible','hidden','pending','deleted') NOT NULL DEFAULT 'visible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comment_replies_comment (comment_id),
  KEY idx_comment_replies_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_reactions (
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  reaction ENUM('like','love','haha','wow','sad','angry') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (comment_id, user_id),
  KEY idx_comment_reactions_reaction (reaction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  document_id INT UNSIGNED DEFAULT NULL,
  comment_id INT UNSIGNED DEFAULT NULL,
  reply_id INT UNSIGNED DEFAULT NULL,
  from_user_id INT UNSIGNED DEFAULT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read_created (user_id, is_read, created_at),
  KEY idx_notifications_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  device_label VARCHAR(120) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint(191)),
  KEY idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal / news
CREATE TABLE IF NOT EXISTS journal (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('annonce','defi','sondage') NOT NULL,
  title VARCHAR(200) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  starts_at DATETIME DEFAULT NULL,
  ends_at DATETIME DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  closed_by INT UNSIGNED DEFAULT NULL,
  author_id INT UNSIGNED DEFAULT NULL,
  admin_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_journal_status (status),
  KEY idx_journal_type (type),
  KEY idx_journal_starts_at (starts_at),
  KEY idx_journal_ends_at (ends_at),
  KEY idx_journal_closed_at (closed_at),
  KEY idx_journal_closed_by (closed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_options (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id INT UNSIGNED NOT NULL,
  label VARCHAR(200) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_journal_options_journal (journal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_votes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_vote (journal_id, user_id),
  KEY idx_journal_votes_option (option_id),
  KEY idx_journal_votes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_defis (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  note TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_defi (journal_id, user_id),
  KEY idx_journal_defis_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Media
CREATE TABLE IF NOT EXISTS media_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  type ENUM('image','video','lien') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  poster_path VARCHAR(255) DEFAULT NULL,
  category VARCHAR(100) DEFAULT NULL,
  category_id INT UNSIGNED DEFAULT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('published','archived') NOT NULL DEFAULT 'published',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_media_category (category_id),
  KEY idx_media_created_by (created_by),
  KEY idx_media_poster (poster_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Institution content (legacy + new)
CREATE TABLE IF NOT EXISTS institution_content (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cle VARCHAR(80) NOT NULL,
  section VARCHAR(80) NOT NULL,
  label VARCHAR(120) DEFAULT NULL,
  valeur MEDIUMTEXT DEFAULT NULL,
  ordre SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_institution_section (section),
  KEY idx_institution_key (cle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_blocs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  section_key VARCHAR(80) NOT NULL,
  section_label VARCHAR(120) NOT NULL,
  bloc_type ENUM('texte','image','galerie','stats','citation','colonnes','separateur') NOT NULL DEFAULT 'texte',
  contenu MEDIUMTEXT,
  image_path VARCHAR(255),
  config_json TEXT,
  ordre SMALLINT NOT NULL DEFAULT 0,
  visible TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- School email domains
CREATE TABLE IF NOT EXISTS school_email_domains (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain VARCHAR(100) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_domains_domain (domain),
  KEY idx_school_domains_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App settings
CREATE TABLE IF NOT EXISTS app_settings (
  skey VARCHAR(80) NOT NULL,
  svalue TEXT DEFAULT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email log
CREATE TABLE IF NOT EXISTS email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED DEFAULT NULL,
  email_to VARCHAR(150) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  template_name VARCHAR(50) NOT NULL,
  status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_log_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting
CREATE TABLE IF NOT EXISTS login_attempts (
  ip VARCHAR(45) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
  last_attempt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Badge Or assignments
CREATE TABLE IF NOT EXISTS badge_or_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NOT NULL,
  action ENUM('grant','revoke') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_badge_or_user (user_id),
  KEY idx_badge_or_admin (assigned_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History (views and downloads)
CREATE TABLE IF NOT EXISTS history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  action ENUM('view','download') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_history_user_action (user_id, action, created_at),
  KEY idx_history_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra indexes
ALTER TABLE documents
  ADD KEY idx_documents_approved_at (approved_at);

-- Foreign keys
ALTER TABLE users
  ADD CONSTRAINT fk_users_filiere
    FOREIGN KEY (filiere_id) REFERENCES filieres(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_users_licence
    FOREIGN KEY (licence_id) REFERENCES licences(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE licence_filieres
  ADD CONSTRAINT fk_licence_filieres_licence
    FOREIGN KEY (licence_id) REFERENCES licences(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_licence_filieres_filiere
    FOREIGN KEY (filiere_id) REFERENCES filieres(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE modules
  ADD CONSTRAINT fk_modules_licence
    FOREIGN KEY (licence_id) REFERENCES licences(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE matieres
  ADD CONSTRAINT fk_matieres_module
    FOREIGN KEY (module_id) REFERENCES modules(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE documents
  ADD CONSTRAINT fk_documents_uploader
    FOREIGN KEY (uploader_id) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_documents_filiere
    FOREIGN KEY (filiere_id) REFERENCES filieres(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_documents_licence
    FOREIGN KEY (licence_id) REFERENCES licences(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_documents_module
    FOREIGN KEY (module_id) REFERENCES modules(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_documents_matiere
    FOREIGN KEY (matiere_id) REFERENCES matieres(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_documents_approved_by
    FOREIGN KEY (approved_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE favorites
  ADD CONSTRAINT fk_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_favorites_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE document_likes
  ADD CONSTRAINT fk_document_likes_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_document_likes_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE comments
  ADD CONSTRAINT fk_comments_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_comments_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE comment_replies
  ADD CONSTRAINT fk_comment_replies_comment
    FOREIGN KEY (comment_id) REFERENCES comments(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_comment_replies_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE comment_reactions
  ADD CONSTRAINT fk_comment_reactions_comment
    FOREIGN KEY (comment_id) REFERENCES comments(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_comment_reactions_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE notifications
  ADD CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_notifications_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_notifications_comment
    FOREIGN KEY (comment_id) REFERENCES comments(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_notifications_reply
    FOREIGN KEY (reply_id) REFERENCES comment_replies(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_notifications_from_user
    FOREIGN KEY (from_user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE web_push_subscriptions
  ADD CONSTRAINT fk_web_push_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE journal
  ADD CONSTRAINT fk_journal_author
    FOREIGN KEY (author_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_admin
    FOREIGN KEY (admin_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_closed_by
    FOREIGN KEY (closed_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE journal_options
  ADD CONSTRAINT fk_journal_options_journal
    FOREIGN KEY (journal_id) REFERENCES journal(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE journal_votes
  ADD CONSTRAINT fk_journal_votes_journal
    FOREIGN KEY (journal_id) REFERENCES journal(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_votes_option
    FOREIGN KEY (option_id) REFERENCES journal_options(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_votes_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE journal_defis
  ADD CONSTRAINT fk_journal_defis_journal
    FOREIGN KEY (journal_id) REFERENCES journal(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journal_defis_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

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

ALTER TABLE media
  ADD CONSTRAINT fk_media_category
    FOREIGN KEY (category_id) REFERENCES media_categories(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_media_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE email_log
  ADD CONSTRAINT fk_email_log_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE badge_or_assignments
  ADD CONSTRAINT fk_badge_or_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_badge_or_admin
    FOREIGN KEY (assigned_by) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE history
  ADD CONSTRAINT fk_history_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_history_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;



