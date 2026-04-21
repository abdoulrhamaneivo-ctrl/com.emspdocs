ALTER TABLE filieres
  ADD COLUMN cover_image_path VARCHAR(255) DEFAULT NULL AFTER code,
  ADD COLUMN summary TEXT DEFAULT NULL AFTER cover_image_path,
  ADD COLUMN description_html MEDIUMTEXT DEFAULT NULL AFTER summary;


