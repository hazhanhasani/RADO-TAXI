CREATE TABLE IF NOT EXISTS driver_online_daily (
  driver_id CHAR(36) NOT NULL,
  activity_date DATE NOT NULL,
  online_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(driver_id,activity_date),
  CONSTRAINT fk_driver_online_daily_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_cancellation_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  actor_user_id CHAR(36) NULL,
  actor_role ENUM('passenger','driver','admin','system') NOT NULL,
  reason_code VARCHAR(80) NULL,
  reason_text VARCHAR(500) NULL,
  penalty_amount BIGINT NOT NULL DEFAULT 0,
  refund_amount BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cancel_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_cancel_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_cancel_trip(trip_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  user_id CHAR(36) NOT NULL,
  amount BIGINT NOT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(120) NOT NULL UNIQUE,
  gateway_reference VARCHAR(180) NULL,
  created_by_admin_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  CONSTRAINT fk_refund_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_payment FOREIGN KEY(payment_id) REFERENCES trip_payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_refund_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_types (
  service_key VARCHAR(50) PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(400) NULL,
  fare_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO service_types(service_key,title,description,fare_multiplier,active,sort_order) VALUES
('economy','اقتصادی','سرویس عادی RADO',1.000,1,10),
('special','ویژه','سرویس ویژه با اولویت بیشتر',1.250,1,20),
('phone','تلفنی','ثبت سفر توسط اپراتور',1.000,1,30)
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description);

INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES
('driver_destination_tolerance_km','5',0),
('cancel_penalty_before_accept','0',0),
('cancel_penalty_after_accept','0',0),
('operator_default_distance_m','1000',0),
('operator_default_duration_s','300',0)
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.4.0-operations') ON DUPLICATE KEY UPDATE version=VALUES(version);
