ALTER TABLE driver_documents MODIFY COLUMN document_type ENUM('national_card','national_card_front','national_card_back','driver_license','driver_license_front','driver_license_back','vehicle_card','vehicle_card_front','vehicle_card_back','insurance','inspection','profile_photo','vehicle_front','vehicle_back','vehicle_side','ownership_proof','other') NOT NULL;

CREATE TABLE IF NOT EXISTS driver_verification_profiles (
  driver_id CHAR(36) PRIMARY KEY,
  full_name VARCHAR(160) NULL,
  mobile VARCHAR(20) NULL,
  mobile_verified_at DATETIME NULL,
  national_code VARCHAR(10) NULL,
  birth_date_jalali VARCHAR(12) NULL,
  national_card_serial VARCHAR(48) NULL,
  license_number VARCHAR(64) NULL,
  iban VARCHAR(34) NULL,
  vehicle_owner_national_code VARCHAR(10) NULL,
  vehicle_owner_relation VARCHAR(32) NULL,
  plate_part1 VARCHAR(8) NULL,
  plate_letter VARCHAR(12) NULL,
  plate_part2 VARCHAR(8) NULL,
  plate_part3 VARCHAR(8) NULL,
  vehicle_make VARCHAR(80) NULL,
  vehicle_model VARCHAR(80) NULL,
  vehicle_color VARCHAR(50) NULL,
  shahkar_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  biometric_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  license_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  driving_score_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  active_plates_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  vehicle_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  iban_status ENUM('not_started','pending','passed','failed','review') NOT NULL DEFAULT 'not_started',
  matching_score SMALLINT UNSIGNED NULL,
  liveness_score SMALLINT UNSIGNED NULL,
  speech_score SMALLINT UNSIGNED NULL,
  driving_negative_score INT NULL,
  review_status ENUM('incomplete','ready','submitted','under_review','needs_correction','approved','rejected','suspended') NOT NULL DEFAULT 'incomplete',
  consent_version VARCHAR(40) NULL,
  consent_at DATETIME NULL,
  consent_ip VARCHAR(64) NULL,
  submitted_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  reviewer_admin_id CHAR(36) NULL,
  review_note VARCHAR(700) NULL,
  last_api_ir_error VARCHAR(700) NULL,
  last_api_ir_check_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_verify_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_driver_verify_admin FOREIGN KEY(reviewer_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
  INDEX idx_driver_verify_review(review_status,updated_at),
  INDEX idx_driver_verify_mobile(mobile),
  INDEX idx_driver_verify_national(national_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_verification_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id CHAR(36) NOT NULL,
  check_type ENUM('sms_otp','voice_otp','shahkar','video_live','video_verify','license','driving_score','active_plates','vehicle','iban') NOT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'api.ir',
  status ENUM('started','passed','failed','error','review') NOT NULL,
  http_code SMALLINT UNSIGNED NULL,
  provider_code VARCHAR(40) NULL,
  result_summary_json LONGTEXT NULL,
  response_sha256 CHAR(64) NULL,
  error_message VARCHAR(700) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_verify_check FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_driver_verify_check(driver_id,check_type,created_at),
  INDEX idx_driver_verify_check_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_verification_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id CHAR(36) NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  message VARCHAR(700) NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_by_admin_id CHAR(36) NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_verify_correction FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_driver_verify_correction_admin FOREIGN KEY(created_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
  INDEX idx_driver_verify_correction(driver_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES
('api_ir_shahkar_mode','Shahkar',0),
('api_ir_biometric_mode','VideoLive',0),
('api_ir_matching_threshold','90',0),
('api_ir_liveness_threshold','80',0),
('api_ir_speech_threshold','50',0),
('api_ir_speech_text','من با آگاهی کامل قوانین رانندگی رادو را می‌پذیرم',0),
('driver_verification_require_driving_score','1',0),
('driver_verification_require_active_plates','0',0),
('driver_verification_terms_version','2026-09-08',0)
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.6.5-driver-verification') ON DUPLICATE KEY UPDATE version=VALUES(version);
