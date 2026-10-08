USE tsv_secretary;

ALTER TABLE officers
  ADD COLUMN masterlist_number INT UNSIGNED DEFAULT NULL AFTER id,
  ADD COLUMN oath_taking_text VARCHAR(50) DEFAULT NULL AFTER oath_taking_date,
  ADD COLUMN ndi_signing_text VARCHAR(50) DEFAULT NULL AFTER ndi_signing_date,
  ADD COLUMN contact_telegram VARCHAR(50) DEFAULT NULL AFTER birthday,
  ADD UNIQUE KEY uq_officers_masterlist_number (masterlist_number);
