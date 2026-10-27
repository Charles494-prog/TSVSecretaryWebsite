USE tsv_secretary;

CREATE TABLE IF NOT EXISTS suguan_weeks (
  week_start DATE NOT NULL,
  services LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (week_start)
);

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('locale_name', 'FYM AVENUE'),
  ('district_name', 'MARIKINA'),
  ('print_form_monthly_attendance', '0');
