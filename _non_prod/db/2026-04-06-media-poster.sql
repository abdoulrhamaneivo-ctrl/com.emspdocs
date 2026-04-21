-- EMSP Docs
-- Media posters for videos
-- Date: 2026-04-06

USE emsp_docs;

ALTER TABLE media
  ADD COLUMN poster_path VARCHAR(255) DEFAULT NULL AFTER file_path,
  ADD KEY idx_media_poster (poster_path);


