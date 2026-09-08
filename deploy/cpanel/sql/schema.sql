CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(64) NOT NULL UNIQUE,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  phone VARCHAR(20) NOT NULL UNIQUE,
  role ENUM('passenger','driver','admin') NOT NULL,
  full_name VARCHAR(160) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role(role),
  INDEX idx_users_active(is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id CHAR(36) PRIMARY KEY,
  phone VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drivers (
  user_id CHAR(36) PRIMARY KEY,
  status ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
  national_id VARCHAR(32) NULL,
  license_number VARCHAR(64) NULL,
  plate_number VARCHAR(64) NULL,
  vehicle_make VARCHAR(80) NULL,
  vehicle_model VARCHAR(80) NULL,
  vehicle_color VARCHAR(50) NULL,
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_drivers_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_drivers_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_presence (
  driver_id CHAR(36) PRIMARY KEY,
  is_online TINYINT(1) NOT NULL DEFAULT 0,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  heading SMALLINT NULL,
  speed_kph DECIMAL(7,2) NULL,
  last_seen_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_presence_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_presence_online(is_online,last_seen_at),
  INDEX idx_presence_geo(latitude,longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trips (
  id CHAR(36) PRIMARY KEY,
  passenger_id CHAR(36) NOT NULL,
  driver_id CHAR(36) NULL,
  status ENUM('requested','searching','driver_assigned','driver_arriving','arrived','in_progress','completed','cancelled_by_passenger','cancelled_by_driver','cancelled_by_admin','expired') NOT NULL DEFAULT 'requested',
  pickup_lat DECIMAL(10,7) NOT NULL,
  pickup_lng DECIMAL(10,7) NOT NULL,
  destination_lat DECIMAL(10,7) NOT NULL,
  destination_lng DECIMAL(10,7) NOT NULL,
  pickup_label VARCHAR(255) NULL,
  destination_label VARCHAR(255) NULL,
  estimated_distance_m INT UNSIGNED NULL,
  estimated_duration_s INT UNSIGNED NULL,
  estimated_fare BIGINT NULL,
  final_fare BIGINT NULL,
  requested_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  arrived_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancellation_reason VARCHAR(255) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_trips_passenger FOREIGN KEY(passenger_id) REFERENCES users(id),
  CONSTRAINT fk_trips_driver FOREIGN KEY(driver_id) REFERENCES users(id),
  INDEX idx_trips_passenger(passenger_id,created_at),
  INDEX idx_trips_driver(driver_id,created_at),
  INDEX idx_trips_status(status,requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  driver_id CHAR(36) NOT NULL,
  offered_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  accepted TINYINT(1) NULL,
  CONSTRAINT fk_offer_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_offer_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_trip_driver(trip_id,driver_id),
  INDEX idx_offer_driver(driver_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pricing_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  base_fare BIGINT NOT NULL DEFAULT 0,
  per_km BIGINT NOT NULL DEFAULT 0,
  per_minute BIGINT NOT NULL DEFAULT 0,
  waiting_per_minute BIGINT NOT NULL DEFAULT 0,
  minimum_fare BIGINT NOT NULL DEFAULT 0,
  surge_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from DATETIME NULL,
  effective_to DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pricing_active(active,effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NULL,
  trip_id CHAR(36) NULL,
  entry_type VARCHAR(50) NOT NULL,
  amount BIGINT NOT NULL,
  balance_after BIGINT NULL,
  idempotency_key VARCHAR(120) NOT NULL UNIQUE,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ledger_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ledger_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  INDEX idx_ledger_user(user_id,created_at),
  INDEX idx_ledger_trip(trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  from_user_id CHAR(36) NOT NULL,
  to_user_id CHAR(36) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rating_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_rating_from FOREIGN KEY(from_user_id) REFERENCES users(id),
  CONSTRAINT fk_rating_to FOREIGN KEY(to_user_id) REFERENCES users(id),
  UNIQUE KEY uq_rating_trip_from(trip_id,from_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value LONGTEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings(setting_key,setting_value,is_secret)
VALUES ('default_driver_commission_rate','10.00',0)
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.2.3') ON DUPLICATE KEY UPDATE version=VALUES(version);
INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.3.0-trip-lifecycle') ON DUPLICATE KEY UPDATE version=VALUES(version);
