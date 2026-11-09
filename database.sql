CREATE DATABASE IF NOT EXISTS tsv_secretary
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tsv_secretary;

CREATE TABLE IF NOT EXISTS officers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  masterlist_number INT UNSIGNED DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  tungkulin VARCHAR(150) NOT NULL,
  oath_taking_date DATE DEFAULT NULL,
  oath_taking_text VARCHAR(50) DEFAULT NULL,
  ndi_signing_date DATE DEFAULT NULL,
  ndi_signing_text VARCHAR(50) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  birthday DATE DEFAULT NULL,
  wedding_anniversary DATE DEFAULT NULL,
  contact_telegram VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_officers_masterlist_number (masterlist_number)
);

CREATE TABLE IF NOT EXISTS suguan_weeks (
  week_start DATE NOT NULL,
  services LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (week_start)
);

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value VARCHAR(5000) NOT NULL DEFAULT '',
  PRIMARY KEY (setting_key)
);

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('pastor', ''),
  ('pd_tagasubaybay', ''),
  ('tsv_lead', ''),
  ('assistant_tsv_lead', ''),
  ('tsv_secretary', ''),
  ('locale_name', 'FYM AVENUE'),
  ('district_name', 'MARIKINA'),
  ('district_supervisor', 'CYRUS JOSE E. DIZON'),
  ('district_tsv_overseer', ''),
  ('webex_display_name', 'MKN-FYM Avenue'),
  ('print_form_pagpapalaganp', '1'),
  ('print_form_weekly_attendance', '1'),
  ('print_form_meeting_agenda', '1'),
  ('print_form_suguan', '1'),
  ('print_form_tsv_checklist', '1'),
  ('print_form_resumen_form6', '1'),
  ('print_form_hymnfiles_deleted', '1'),
  ('print_form_tsv_form3', '0'),
  ('print_form_directory_report', '0'),
  ('print_form_monthly_attendance', '0'),
  ('weekly_meeting_datetime', ''),
  ('weekly_meeting_venue', ''),
  ('monthly_recruits', '[]');

CREATE TABLE IF NOT EXISTS memory_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  taken_on DATE NOT NULL,
  caption VARCHAR(2000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memory_posts_taken_on (taken_on)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memory_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  kind VARCHAR(5) NOT NULL DEFAULT 'photo',
  checksum CHAR(40) NOT NULL,
  mime VARCHAR(20) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  complete TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_at DATETIME DEFAULT NULL,
  photo LONGTEXT NOT NULL,
  thumb MEDIUMTEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memory_photos_post (post_id, position),
  KEY idx_memory_photos_checksum (checksum)
) CHARACTER SET ascii COLLATE ascii_bin;

CREATE TABLE IF NOT EXISTS memory_video_chunks (
  media_id INT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL,
  data MEDIUMTEXT NOT NULL,
  PRIMARY KEY (media_id, seq)
) CHARACTER SET ascii COLLATE ascii_bin;
