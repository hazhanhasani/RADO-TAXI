CREATE TABLE IF NOT EXISTS user_wallets (
  user_id CHAR(36) PRIMARY KEY,
  balance BIGINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wallet_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passenger_favorites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  passenger_id CHAR(36) NOT NULL,
  kind ENUM('home','work','favorite') NOT NULL DEFAULT 'favorite',
  title VARCHAR(120) NOT NULL,
  label VARCHAR(255) NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_favorite_passenger FOREIGN KEY(passenger_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_favorites_passenger(passenger_id,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_routes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  passenger_id CHAR(36) NOT NULL,
  title VARCHAR(120) NOT NULL,
  pickup_label VARCHAR(255) NOT NULL,
  pickup_lat DECIMAL(10,7) NOT NULL,
  pickup_lng DECIMAL(10,7) NOT NULL,
  destination_label VARCHAR(255) NOT NULL,
  destination_lat DECIMAL(10,7) NOT NULL,
  destination_lng DECIMAL(10,7) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_saved_route_passenger FOREIGN KEY(passenger_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_saved_routes_passenger(passenger_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_preferences (
  trip_id CHAR(36) PRIMARY KEY,
  pickup_note VARCHAR(500) NULL,
  silent_trip TINYINT(1) NOT NULL DEFAULT 0,
  payment_method ENUM('cash','wallet','online','corporate') NOT NULL DEFAULT 'cash',
  service_type VARCHAR(50) NOT NULL DEFAULT 'economy',
  scheduled_for DATETIME NULL,
  promo_code VARCHAR(64) NULL,
  corporate_account_id BIGINT UNSIGNED NULL,
  original_fare BIGINT NULL,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  repriced_fare BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_trip_preferences_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  INDEX idx_trip_preferences_schedule(scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_stops (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  sequence_no SMALLINT UNSIGNED NOT NULL,
  label VARCHAR(255) NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  wait_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  arrived_at DATETIME NULL,
  departed_at DATETIME NULL,
  CONSTRAINT fk_trip_stop_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  UNIQUE KEY uq_trip_stop_sequence(trip_id,sequence_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  change_type ENUM('destination','stop_added','stop_removed','payment_method','pickup_note','repricing') NOT NULL,
  old_value_json LONGTEXT NULL,
  new_value_json LONGTEXT NULL,
  fare_before BIGINT NULL,
  fare_after BIGINT NULL,
  changed_by_user_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trip_changes_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_changes_user FOREIGN KEY(changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_trip_changes_trip(trip_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  discount_type ENUM('fixed','percent') NOT NULL,
  discount_value DECIMAL(12,2) NOT NULL,
  max_discount BIGINT NULL,
  min_fare BIGINT NOT NULL DEFAULT 0,
  total_limit INT UNSIGNED NULL,
  per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_promo_active(active,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_redemptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  promo_id BIGINT UNSIGNED NOT NULL,
  user_id CHAR(36) NOT NULL,
  trip_id CHAR(36) NULL,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_redemption_promo FOREIGN KEY(promo_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
  CONSTRAINT fk_redemption_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_redemption_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  INDEX idx_redemption_user(user_id,promo_id),
  INDEX idx_redemption_trip(trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  passenger_id CHAR(36) NOT NULL,
  method ENUM('cash','wallet','online','corporate') NOT NULL,
  amount BIGINT NOT NULL,
  status ENUM('pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
  gateway VARCHAR(80) NULL,
  gateway_reference VARCHAR(180) NULL,
  idempotency_key VARCHAR(120) NOT NULL UNIQUE,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_trip_payment_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_payment_passenger FOREIGN KEY(passenger_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_trip_payment_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratings_extended (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  target_role ENUM('driver','passenger') NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  tags_json LONGTEXT NULL,
  comment VARCHAR(700) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rating_ext_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_rating_ext_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_rating_ext(trip_id,user_id,target_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_shares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  token CHAR(48) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trip_share_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  INDEX idx_trip_share_token(token,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  trip_id CHAR(36) NULL,
  subject VARCHAR(180) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'general',
  status ENUM('open','waiting_user','waiting_admin','closed') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  INDEX idx_ticket_status(status,priority,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  sender_user_id CHAR(36) NULL,
  sender_role ENUM('passenger','driver','admin','system') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_message_ticket FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_message_user FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_support_message_ticket(ticket_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  purpose VARCHAR(50) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_otp_phone(phone,purpose,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  platform ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  token VARCHAR(512) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_device_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_device_token(token(190)),
  INDEX idx_device_token_user(user_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  type VARCHAR(80) NOT NULL DEFAULT 'general',
  data_json LONGTEXT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notification_user(user_id,read_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_preferences (
  driver_id CHAR(36) PRIMARY KEY,
  destination_label VARCHAR(255) NULL,
  destination_lat DECIMAL(10,7) NULL,
  destination_lng DECIMAL(10,7) NULL,
  max_pickup_distance_km DECIMAL(6,2) NULL,
  min_fare BIGINT NULL,
  auto_accept TINYINT(1) NOT NULL DEFAULT 0,
  auto_accept_radius_km DECIMAL(6,2) NULL,
  auto_accept_min_fare BIGINT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_preferences_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id CHAR(36) NOT NULL,
  document_type ENUM('national_card','driver_license','vehicle_card','insurance','inspection','other') NOT NULL,
  file_path VARCHAR(255) NULL,
  document_number VARCHAR(120) NULL,
  expires_at DATE NULL,
  status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  note VARCHAR(400) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_document_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_driver_document_status(driver_id,status,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_missions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description VARCHAR(700) NULL,
  target_type ENUM('trips','online_minutes','revenue') NOT NULL,
  target_value BIGINT NOT NULL,
  reward_amount BIGINT NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_driver_mission_active(active,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_mission_progress (
  mission_id BIGINT UNSIGNED NOT NULL,
  driver_id CHAR(36) NOT NULL,
  progress_value BIGINT NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  rewarded_at DATETIME NULL,
  PRIMARY KEY(mission_id,driver_id),
  CONSTRAINT fk_mission_progress_mission FOREIGN KEY(mission_id) REFERENCES driver_missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mission_progress_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_settlements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id CHAR(36) NOT NULL,
  amount BIGINT NOT NULL,
  status ENUM('requested','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'requested',
  note VARCHAR(400) NULL,
  requested_at DATETIME NOT NULL,
  reviewed_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_settlement_driver FOREIGN KEY(driver_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_settlement_status(status,requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pricing_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  days_of_week VARCHAR(32) NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pricing_schedule(active,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_areas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  area_type ENUM('service','surcharge','blocked','outside_city') NOT NULL DEFAULT 'service',
  polygon_json LONGTEXT NOT NULL,
  fare_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  flat_surcharge BIGINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_area_active(active,area_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporate_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  contact_phone VARCHAR(20) NULL,
  billing_mode ENUM('prepaid','postpaid') NOT NULL DEFAULT 'prepaid',
  credit_limit BIGINT NOT NULL DEFAULT 0,
  balance BIGINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporate_members (
  corporate_account_id BIGINT UNSIGNED NOT NULL,
  user_id CHAR(36) NOT NULL,
  employee_code VARCHAR(80) NULL,
  monthly_limit BIGINT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY(corporate_account_id,user_id),
  CONSTRAINT fk_corporate_member_account FOREIGN KEY(corporate_account_id) REFERENCES corporate_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_corporate_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_user_id CHAR(36) NOT NULL,
  referred_user_id CHAR(36) NOT NULL,
  referral_code VARCHAR(32) NOT NULL,
  reward_amount BIGINT NOT NULL DEFAULT 0,
  rewarded_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_referral_referrer FOREIGN KEY(referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_referral_referred FOREIGN KEY(referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_referral_referred(referred_user_id),
  INDEX idx_referral_code(referral_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  points INT NOT NULL,
  reason VARCHAR(120) NOT NULL,
  trip_id CHAR(36) NULL,
  idempotency_key VARCHAR(120) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_loyalty_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_loyalty_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  INDEX idx_loyalty_user(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operator_bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  passenger_name VARCHAR(160) NULL,
  passenger_phone VARCHAR(20) NOT NULL,
  pickup_label VARCHAR(255) NOT NULL,
  pickup_lat DECIMAL(10,7) NOT NULL,
  pickup_lng DECIMAL(10,7) NOT NULL,
  destination_label VARCHAR(255) NOT NULL,
  destination_lat DECIMAL(10,7) NOT NULL,
  destination_lng DECIMAL(10,7) NOT NULL,
  scheduled_for DATETIME NULL,
  trip_id CHAR(36) NULL,
  created_by_admin_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_operator_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  INDEX idx_operator_booking_phone(passenger_phone,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id CHAR(36) NULL,
  actor_role VARCHAR(40) NOT NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id VARCHAR(120) NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created(created_at),
  INDEX idx_audit_entity(entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel VARCHAR(120) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_realtime_channel(channel,id),
  INDEX idx_realtime_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS health_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  component VARCHAR(80) NOT NULL,
  severity ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  code VARCHAR(100) NOT NULL,
  message VARCHAR(500) NOT NULL,
  context_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_health_component(component,created_at),
  INDEX idx_health_severity(severity,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES
('dispatch_radius_1_km','1.5',0),
('dispatch_radius_2_km','3',0),
('dispatch_radius_3_km','5',0),
('dispatch_batch_size','4',0),
('dispatch_offer_timeout_seconds','25',0),
('otp_ttl_seconds','120',0),
('otp_sms_webhook','',1),
('fcm_server_key','',1),
('online_payment_gateway','',0),
('online_payment_api_key','',1),
('realtime_poll_seconds','4',0),
('loyalty_points_per_trip','10',0),
('referral_reward_amount','0',0)
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.4.0-platform') ON DUPLICATE KEY UPDATE version=VALUES(version);
