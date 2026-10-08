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
  contact_telegram VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_officers_masterlist_number (masterlist_number)
);

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value VARCHAR(150) NOT NULL DEFAULT '',
  PRIMARY KEY (setting_key)
);

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('pastor', ''),
  ('pd_tagasubaybay', ''),
  ('tsv_lead', ''),
  ('assistant_tsv_lead', ''),
  ('tsv_secretary', ''),
  ('print_form_pagpapalaganp', '1'),
  ('print_form_weekly_attendance', '1'),
  ('print_form_meeting_agenda', '1'),
  ('print_form_suguan', '1'),
  ('print_form_tsv_checklist', '1'),
  ('print_form_resumen_form6', '1'),
  ('print_form_hymnfiles_deleted', '1'),
  ('print_form_tsv_form3', '0'),
  ('print_form_directory_report', '0');
